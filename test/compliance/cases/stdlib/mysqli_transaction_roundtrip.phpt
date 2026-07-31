--TEST--
ext/mysqli transaction begin/commit/rollback round-trip (#21825, ext/mysqli/mysqli_api.c)
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
$link = mysqli_connect($host, $user, $pass, $db, $port);
if (!$link) {
    echo 'connect_fail';
    exit(0);
}
mysqli_query($link, 'DROP TABLE IF EXISTS phpc_mysqli_tx');
mysqli_query($link, 'CREATE TABLE phpc_mysqli_tx (id INT PRIMARY KEY, v INT)');
mysqli_autocommit($link, false);
mysqli_begin_transaction($link);
mysqli_query($link, 'INSERT INTO phpc_mysqli_tx VALUES (1, 100)');
mysqli_rollback($link);
$res = mysqli_query($link, 'SELECT COUNT(*) AS c FROM phpc_mysqli_tx');
$row = mysqli_fetch_assoc($res);
echo 'after_rollback:', (int) $row['c'], "\n";
mysqli_begin_transaction($link);
mysqli_query($link, 'INSERT INTO phpc_mysqli_tx VALUES (2, 200)');
mysqli_commit($link);
$res = mysqli_query($link, 'SELECT COUNT(*) AS c FROM phpc_mysqli_tx');
$row = mysqli_fetch_assoc($res);
echo 'after_commit:', (int) $row['c'], "\n";
mysqli_autocommit($link, true);
mysqli_query($link, 'DROP TABLE phpc_mysqli_tx');
mysqli_close($link);
?>
--EXPECT--
after_rollback:0
after_commit:1
