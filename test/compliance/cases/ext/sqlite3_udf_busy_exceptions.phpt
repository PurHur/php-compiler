--TEST--
ext/sqlite3 busyTimeout/enableExceptions/createFunction (#19862, ext/sqlite3/sqlite3.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$db = new SQLite3(':memory:');
echo 'busy=', $db->busyTimeout(100) ? '1' : '0', "\n";
// php-src returns prior exception mode (default false).
echo 'enable_prior=', $db->enableExceptions(true) ? '1' : '0', "\n";
$ok = $db->createFunction('dbl', static function ($x) { return (int) $x * 2; }, 1);
echo 'create=', $ok ? '1' : '0', "\n";
echo 'dbl=', $db->querySingle('SELECT dbl(21)'), "\n";
$db->createFunction('greet', static function ($n) { return 'hi '.$n; }, 1);
echo 'greet=', $db->querySingle("SELECT greet('x')"), "\n";
try {
    $db->exec('NOT SQL');
    echo "fail: expected exception\n";
} catch (Throwable $e) {
    echo 'ex=', get_class($e), "\n";
}
?>
--EXPECT--
busy=1
enable_prior=0
create=1
dbl=42
greet=hi x
ex=SQLite3Exception
