--TEST--
posix_sysconf/pathconf/fpathconf/eaccess + POSIX_SC_*/POSIX_PC_* (#20509, #22483)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'posix_sysconf', 'posix_pathconf', 'posix_fpathconf', 'posix_eaccess',
] as $f) {
    echo $f, ' ', function_exists($f) ? 'Y' : 'N', "\n";
}

foreach ([
    'POSIX_SC_ARG_MAX', 'POSIX_SC_CHILD_MAX', 'POSIX_SC_CLK_TCK', 'POSIX_SC_PAGESIZE',
    'POSIX_SC_NPROCESSORS_CONF', 'POSIX_SC_NPROCESSORS_ONLN',
    'POSIX_PC_LINK_MAX', 'POSIX_PC_PATH_MAX', 'POSIX_PC_NAME_MAX', 'POSIX_PC_PIPE_BUF',
    'POSIX_PC_CHOWN_RESTRICTED', 'POSIX_PC_NO_TRUNC', 'POSIX_PC_ALLOC_SIZE_MIN', 'POSIX_PC_SYMLINK_MAX',
] as $c) {
    echo $c, ' ', defined($c) ? 'Y=' . constant($c) : 'N', "\n";
}

$n = posix_sysconf(POSIX_SC_NPROCESSORS_ONLN);
echo 'nproc ', is_int($n) && $n > 0 ? 'ok' : 'bad', "\n";

$pm = posix_pathconf('/', POSIX_PC_PATH_MAX);
echo 'pathconf ', (false !== $pm && is_int($pm) && $pm > 0) ? 'ok' : 'bad', "\n";

$fm = posix_fpathconf(0, POSIX_PC_PATH_MAX);
echo 'fpathconf-fd ', (false !== $fm && is_int($fm) && $fm > 0) ? 'ok' : 'bad', "\n";

$fs = posix_fpathconf(STDIN, POSIX_PC_PATH_MAX);
echo 'fpathconf-stdin ', (false !== $fs && is_int($fs) && $fs > 0) ? 'ok' : 'bad', "\n";

echo 'eaccess ', posix_eaccess('/tmp', POSIX_R_OK | POSIX_X_OK) ? '1' : '0', "\n";
echo 'eaccess-miss ', posix_eaccess('/no/such/path-' . getmypid(), POSIX_F_OK) ? '1' : '0', "\n";

try {
    posix_pathconf('', POSIX_PC_PATH_MAX);
    echo "empty-path-bad\n";
} catch (ValueError $e) {
    echo str_contains($e->getMessage(), 'must not be empty') ? "empty-path-ok\n" : "empty-path-msg\n";
}

try {
    posix_fpathconf([], POSIX_PC_PATH_MAX);
    echo "arr-fd-bad\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'int|resource') ? "arr-fd-ok\n" : "arr-fd-msg\n";
}
?>
--EXPECT--
posix_sysconf Y
posix_pathconf Y
posix_fpathconf Y
posix_eaccess Y
POSIX_SC_ARG_MAX Y=0
POSIX_SC_CHILD_MAX Y=1
POSIX_SC_CLK_TCK Y=2
POSIX_SC_PAGESIZE Y=30
POSIX_SC_NPROCESSORS_CONF Y=83
POSIX_SC_NPROCESSORS_ONLN Y=84
POSIX_PC_LINK_MAX Y=0
POSIX_PC_PATH_MAX Y=4
POSIX_PC_NAME_MAX Y=3
POSIX_PC_PIPE_BUF Y=5
POSIX_PC_CHOWN_RESTRICTED Y=6
POSIX_PC_NO_TRUNC Y=7
POSIX_PC_ALLOC_SIZE_MIN Y=18
POSIX_PC_SYMLINK_MAX Y=19
nproc ok
pathconf ok
fpathconf-fd ok
fpathconf-stdin ok
eaccess 1
eaccess-miss 0
empty-path-ok
arr-fd-ok
