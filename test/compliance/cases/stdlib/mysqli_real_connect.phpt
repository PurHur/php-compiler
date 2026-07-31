--TEST--
ext/mysqli real_connect, charset, multi_query (#21791, ext/mysqli/mysqli_api.c)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--SKIPIF--
<?php
if (!getenv('MYSQLI_TEST_DSN')) {
    echo 'skip no MYSQLI_TEST_DSN';
}
?>
--FILE--
<?php
$parts = parse_url(getenv('MYSQLI_TEST_DSN'));
$host = $parts['host'] ?? '127.0.0.1';
$port = isset($parts['port']) ? (int) $parts['port'] : 3306;
$user = $parts['user'] ?? 'root';
$pass = $parts['pass'] ?? '';
$db = ltrim($parts['path'] ?? '/test', '/');
$link = mysqli_init();
if (!$link) {
    echo 'init_fail';
    exit(0);
}
if (!mysqli_real_connect($link, $host, $user, $pass, $db, $port)) {
    echo 'connect_fail';
    exit(0);
}
echo 'charset:', mysqli_set_charset($link, 'utf8mb4') ? 'ok' : 'fail', "\n";
if (!mysqli_multi_query($link, 'SELECT 1 AS a; SELECT 2 AS b')) {
    echo 'multi_fail';
    exit(0);
}
$res = mysqli_store_result($link);
$row = mysqli_fetch_assoc($res);
echo 'first:', (int) $row['a'], "\n";
mysqli_free_result($res);
echo 'more:', mysqli_more_results($link) ? 'yes' : 'no', "\n";
echo 'next:', mysqli_next_result($link) ? 'ok' : 'fail', "\n";
$res = mysqli_use_result($link);
$row = mysqli_fetch_assoc($res);
echo 'second:', (int) $row['b'], "\n";
mysqli_free_result($res);
echo 'more_after:', mysqli_more_results($link) ? 'yes' : 'no', "\n";
$stat = mysqli_stat($link);
echo 'stat:', is_string($stat) && $stat !== '' ? 'ok' : 'fail', "\n";
mysqli_close($link);
?>
--EXPECT--
charset:ok
first:1
more:yes
next:ok
second:2
more_after:no
stat:ok
