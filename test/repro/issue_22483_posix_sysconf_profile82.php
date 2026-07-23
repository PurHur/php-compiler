<?php
// Repro #22483 — posix_sysconf family + SC/PC constants must follow language profile (PHP 8.3+)
declare(strict_types=1);

foreach (['posix_sysconf', 'posix_pathconf', 'posix_fpathconf', 'posix_eaccess'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', PHP_EOL;
}
foreach (['POSIX_SC_PAGESIZE', 'POSIX_PC_PATH_MAX'] as $c) {
    echo $c, '=', defined($c) ? '1' : '0', PHP_EOL;
}
