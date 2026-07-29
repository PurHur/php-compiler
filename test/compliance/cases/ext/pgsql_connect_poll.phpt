--TEST--
ext/pgsql pg_connect_poll + CONNECT_ASYNC / POLLING constants (#21896)
--SKIPIF--
<?php
// Host Zend often lacks ext/pgsql; in-tree path uses PHP_COMPILER_ENABLE_PGSQL (#24994).
if (!extension_loaded('pgsql')) {
    $en = getenv('PHP_COMPILER_ENABLE_PGSQL');
    if (!is_string($en) || '' === trim($en) || in_array(strtolower(trim($en)), ['0', 'false', 'off', 'no'], true)) {
        die('skip pgsql withheld');
    }
}
?>
--ENV--
PHP_COMPILER_ENABLE_PGSQL=1
--FILE--
<?php
declare(strict_types=1);
foreach (['pg_connect_poll', 'pg_socket', 'pg_consume_input'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
foreach ([
    'PGSQL_CONNECT_ASYNC',
    'PGSQL_CONNECT_FORCE_NEW',
    'PGSQL_POLLING_FAILED',
    'PGSQL_POLLING_READING',
    'PGSQL_POLLING_WRITING',
    'PGSQL_POLLING_OK',
    'PGSQL_POLLING_ACTIVE',
] as $c) {
    echo $c, '=', defined($c) ? (int) constant($c) : -1, "\n";
}
$conn = @pg_connect('host=127.0.0.1 port=1 dbname=nope user=nope password=nope', PGSQL_CONNECT_ASYNC);
if (false === $conn) {
    echo "async_start=0\n";
} else {
    echo "async_start=1\n";
    $poll = pg_connect_poll($conn);
    $n = 0;
    while ($poll !== PGSQL_POLLING_OK && $poll !== PGSQL_POLLING_FAILED && $n < 64) {
        usleep(10000);
        $poll = pg_connect_poll($conn);
        $n++;
    }
    echo 'terminal_failed=', (int) ($poll === PGSQL_POLLING_FAILED), "\n";
    @pg_close($conn);
}
?>
--EXPECT--
pg_connect_poll=1
pg_socket=1
pg_consume_input=1
PGSQL_CONNECT_ASYNC=4
PGSQL_CONNECT_FORCE_NEW=2
PGSQL_POLLING_FAILED=0
PGSQL_POLLING_READING=1
PGSQL_POLLING_WRITING=2
PGSQL_POLLING_OK=3
PGSQL_POLLING_ACTIVE=4
async_start=1
terminal_failed=1
