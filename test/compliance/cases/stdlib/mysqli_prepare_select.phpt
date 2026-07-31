--TEST--
ext/mysqli prepared statement SELECT ? round-trip (#21788, ext/mysqli/mysqli_api.c)
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
$stmt = mysqli_prepare($link, 'SELECT ? AS n');
if (!$stmt) {
    echo 'prepare_fail';
    exit(0);
}
$n = 42;
mysqli_stmt_bind_param($stmt, 'i', $n);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $out);
$ok = mysqli_stmt_fetch($stmt);
echo $ok ? 'fetch_ok' : 'fetch_fail', "\n";
echo (int) $out, "\n";
mysqli_stmt_close($stmt);
mysqli_close($link);
?>
--EXPECT--
fetch_ok
42
