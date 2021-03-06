<?php

namespace PHPPgAdmin\Controller;

/**
 * Base controller class
 */
class DbexportController extends BaseController
{
    public $_name = 'DbexportController';

    public function render()
    {
        $conf   = $this->conf;
        $misc   = $this->misc;
        $lang   = $this->lang;
        $data   = $misc->getDatabaseAccessor();
        $action = $this->action;

        // Prevent timeouts on large exports
        set_time_limit(0);

        // Include application functions
        $f_schema = $f_object = '';
        $this->setNoOutput(true);

        ini_set('memory_limit', '768M');

        // Are we doing a cluster-wide dump or just a per-database dump
        $dumpall = ($_REQUEST['subject'] == 'server');
        $this->prtrace('REQUEST[subject]', $_REQUEST['subject']);

        // Check that database dumps are enabled.
        if ($misc->isDumpEnabled($dumpall)) {
            $server_info = $misc->getServerInfo();

            // Get the path of the pg_dump/pg_dumpall executable
            $exe = $misc->escapeShellCmd($server_info[$dumpall ? 'pg_dumpall_path' : 'pg_dump_path']);

            // Obtain the pg_dump version number and check if the path is good
            $version = [];
            preg_match("/(\d+(?:\.\d+)?)(?:\.\d+)?.*$/", exec($exe . ' --version'), $version);

            $this->prtrace('$exe', $exe, 'version', $version[1]);

            if (empty($version)) {
                if ($dumpall) {
                    printf($lang['strbadpgdumpallpath'], $server_info['pg_dumpall_path']);
                } else {
                    printf($lang['strbadpgdumppath'], $server_info['pg_dump_path']);
                }

                return;
            }

            $this->prtrace('REQUEST[output]', $_REQUEST['output']);
            // Make it do a download, if necessary
            switch ($_REQUEST['output']) {
                case 'show':
                    header('Content-Type: text/plain');
                    break;
                case 'download':
                    // Set headers.  MSIE is totally broken for SSL downloading, so
                    // we need to have it download in-place as plain text
                    if (strstr($_SERVER['HTTP_USER_AGENT'], 'MSIE') && isset($_SERVER['HTTPS'])) {
                        header('Content-Type: text/plain');
                    } else {
                        header('Content-Type: application/download');
                        header('Content-Disposition: attachment; filename=dump.sql');
                    }
                    break;
                case 'gzipped':
                    // MSIE in SSL mode cannot do this - it should never get to this point
                    header('Content-Type: application/download');
                    header('Content-Disposition: attachment; filename=dump.sql.gz');
                    break;
            }

            // Set environmental variables that pg_dump uses
            putenv('PGPASSWORD=' . $server_info['password']);
            putenv('PGUSER=' . $server_info['username']);
            $hostname = $server_info['host'];
            if ($hostname !== null && $hostname != '') {
                putenv('PGHOST=' . $hostname);
            }
            $port = $server_info['port'];
            if ($port !== null && $port != '') {
                putenv('PGPORT=' . $port);
            }

            // Build command for executing pg_dump.  '-i' means ignore version differences.
            if (((float) $version[1]) < 9.5) {
                $cmd = $exe . ' -i';
            } else {
                $cmd = $exe;
            }

            // we are PG 7.4+, so we always have a schema
            if (isset($_REQUEST['schema'])) {
                $f_schema = $_REQUEST['schema'];
                $data->fieldClean($f_schema);
            }

            // Check for a specified table/view
            switch ($_REQUEST['subject']) {
                case 'schema':
                    // This currently works for 8.2+ (due to the orthoganl -t -n issue introduced then)
                    $cmd .= ' -n ' . $misc->escapeShellArg("\"{$f_schema}\"");
                    break;
                case 'table':
                case 'view':
                    $f_object = $_REQUEST[$_REQUEST['subject']];
                    $this->prtrace('f_object', $f_object);
                    $data->fieldClean($f_object);

                    // Starting in 8.2, -n and -t are orthagonal, so we now schema qualify
                    // the table name in the -t argument and quote both identifiers
                    if (((float) $version[1]) >= 8.2) {
                        $cmd .= ' -t ' . $misc->escapeShellArg("\"{$f_schema}\".\"{$f_object}\"");
                    } else {
                        // If we are 7.4 or higher, assume they are using 7.4 pg_dump and
                        // set dump schema as well.  Also, mixed case dumping has been fixed
                        // then..
                        $cmd .= ' -t ' . $misc->escapeShellArg($f_object)
                        . ' -n ' . $misc->escapeShellArg($f_schema);
                    }
            }

            // Check for GZIP compression specified
            if ($_REQUEST['output'] == 'gzipped' && !$dumpall) {
                $cmd .= ' -Z 9';
            }

            switch ($_REQUEST['what']) {
                case 'dataonly':
                    $cmd .= ' -a';
                    if ($_REQUEST['d_format'] == 'sql') {
                        $cmd .= ' --inserts';
                    } elseif (isset($_REQUEST['d_oids'])) {
                        $cmd .= ' -o';
                    }

                    break;
                case 'structureonly':
                    $cmd .= ' -s';
                    if (isset($_REQUEST['s_clean'])) {
                        $cmd .= ' -c';
                    }

                    break;
                case 'structureanddata':
                    if ($_REQUEST['sd_format'] == 'sql') {
                        $cmd .= ' --inserts';
                    } elseif (isset($_REQUEST['sd_oids'])) {
                        $cmd .= ' -o';
                    }

                    if (isset($_REQUEST['sd_clean'])) {
                        $cmd .= ' -c';
                    }

                    break;
            }

            if (!$dumpall) {
                putenv('PGDATABASE=' . $_REQUEST['database']);
            }

            $this->prtrace('ENV VARS', [
                'PGUSER'     => getenv('PGUSER'),
                'PGPASSWORD' => getenv('PGPASSWORD'),
                'PGHOST'     => getenv('PGHOST'),
                'PGPORT'     => getenv('PGPORT'),
                'PGDATABASE' => getenv('PGDATABASE'),
            ]);
            $this->prtrace('cmd', $cmd);

            // Execute command and return the output to the screen
            passthru($cmd);
        }
    }
}
