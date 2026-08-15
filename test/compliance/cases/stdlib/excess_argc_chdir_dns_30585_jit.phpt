--TEST--
stdlib: chdir/gethostbyname/gethostbynamel ArgumentCountError wording JIT (#30585)
--FILE--
<?php
foreach ([
    'chdir_hi' => static fn () => chdir('.', 'extra'),
    'chdir_lo' => static fn () => chdir(),
    'gethostbyname_hi' => static fn () => gethostbyname('localhost', 'extra'),
    'gethostbyname_lo' => static fn () => gethostbyname(),
    'gethostbynamel_hi' => static fn () => gethostbynamel('localhost', 'extra'),
    'gethostbynamel_lo' => static fn () => gethostbynamel(),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$cwd = getcwd();
echo 'ok_chdir=', chdir($cwd) ? '1' : '0', "\n";
$host = gethostbyname('localhost');
echo 'ok_gethostbyname=', (is_string($host) && '' !== $host) ? '1' : '0', "\n";
$list = gethostbynamel('localhost');
echo 'ok_gethostbynamel=', (is_array($list) || false === $list) ? '1' : '0', "\n";
--EXPECT--
chdir_hi ArgumentCountError: chdir() expects exactly 1 argument, 2 given
chdir_lo ArgumentCountError: chdir() expects exactly 1 argument, 0 given
gethostbyname_hi ArgumentCountError: gethostbyname() expects exactly 1 argument, 2 given
gethostbyname_lo ArgumentCountError: gethostbyname() expects exactly 1 argument, 0 given
gethostbynamel_hi ArgumentCountError: gethostbynamel() expects exactly 1 argument, 2 given
gethostbynamel_lo ArgumentCountError: gethostbynamel() expects exactly 1 argument, 0 given
ok_chdir=1
ok_gethostbyname=1
ok_gethostbynamel=1
