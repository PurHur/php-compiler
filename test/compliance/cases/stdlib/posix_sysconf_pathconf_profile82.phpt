--TEST--
posix_sysconf/pathconf/fpathconf/eaccess + POSIX_SC_*/PC_* withheld on PROFILE=8.2 (#22483, re-#20509)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'posix_sysconf', 'posix_pathconf', 'posix_fpathconf', 'posix_eaccess',
] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
foreach (['POSIX_SC_PAGESIZE', 'POSIX_PC_PATH_MAX'] as $c) {
    echo $c, '=', defined($c) ? '1' : '0', "\n";
}
?>
--EXPECT--
posix_sysconf=0
posix_pathconf=0
posix_fpathconf=0
posix_eaccess=0
POSIX_SC_PAGESIZE=0
POSIX_PC_PATH_MAX=0
