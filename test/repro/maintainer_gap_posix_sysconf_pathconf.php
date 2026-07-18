<?php
declare(strict_types=1);

// Repro for #20509 — posix_sysconf / pathconf / fpathconf / eaccess + constants
foreach (['posix_sysconf', 'posix_pathconf', 'posix_fpathconf', 'posix_eaccess'] as $f) {
    echo $f, ' ', function_exists($f) ? 'Y' : 'N', PHP_EOL;
}
echo 'POSIX_SC_NPROCESSORS_ONLN ', defined('POSIX_SC_NPROCESSORS_ONLN') ? 'Y' : 'N', PHP_EOL;
echo 'POSIX_PC_PATH_MAX ', defined('POSIX_PC_PATH_MAX') ? 'Y' : 'N', PHP_EOL;
if (function_exists('posix_sysconf')) {
    $n = posix_sysconf(POSIX_SC_NPROCESSORS_ONLN);
    echo 'nproc=', $n, PHP_EOL;
    echo 'pathconf=', var_export(posix_pathconf('/', POSIX_PC_PATH_MAX), true), PHP_EOL;
    echo 'fpathconf=', var_export(posix_fpathconf(STDIN, POSIX_PC_PATH_MAX), true), PHP_EOL;
    echo 'eaccess=', var_export(posix_eaccess('/tmp', POSIX_R_OK), true), PHP_EOL;
}
