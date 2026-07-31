--TEST--
ext/mysqli refresh + connection stats (#21827, ext/mysqli/mysqli_api.c)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--SKIPIF--
<?php
if (!getenv('MYSQLI_TEST_DSN')) {
    echo 'skip no MYSQLI_TEST_DSN';
}
if (!function_exists('mysqli_get_connection_stats')) {
    echo 'skip host mysqli_get_connection_stats missing';
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
$ok = mysqli_refresh($link, MYSQLI_REFRESH_STATUS);
echo 'refresh:', $ok ? 'ok' : 'fail', "\n";
$stats = mysqli_get_connection_stats($link);
echo 'stats_keys:', count($stats) > 0 ? 'yes' : 'no', "\n";
echo 'has_bytes_sent:', array_key_exists('bytes_sent', $stats) ? 'yes' : 'no', "\n";
mysqli_close($link);
?>
--EXPECT--
refresh:ok
stats_keys:yes
has_bytes_sent:yes
