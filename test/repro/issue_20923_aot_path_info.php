<?php

/**
 * Repro #20923: AOT must expose PATH_INFO from process environ (009-FastCGIWeb smoke).
 *
 * VM:  PATH_INFO=/ping REQUEST_URI=/example.php/ping SCRIPT_NAME=/example.php \
 *      php bin/vm.php test/repro/issue_20923_aot_path_info.php
 * AOT: php bin/compile.php -o /tmp/x test/repro/issue_20923_aot_path_info.php && \
 *      PATH_INFO=/ping REQUEST_URI=/example.php/ping SCRIPT_NAME=/example.php /tmp/x
 */
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
header('Content-Type: text/plain; charset=UTF-8');
if ($pathInfo !== '') {
    echo 'REQUEST_URI='.($_SERVER['REQUEST_URI'] ?? '/')."\n"
        .'SCRIPT_NAME='.($_SERVER['SCRIPT_NAME'] ?? '/example.php')."\n"
        .'PATH_INFO='.$pathInfo."\n";
} else {
    echo "ok\n";
}
