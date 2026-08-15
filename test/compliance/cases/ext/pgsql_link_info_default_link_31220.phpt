--TEST--
ext/pgsql 0-arg link-info/ping FETCH_DEFAULT_LINK deprecation + Error (#31220)
--SKIPIF--
<?php
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
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $errno, string $errstr) use (&$deps): bool {
    if (E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno) {
        if (str_contains($errstr, 'Automatic fetching of PostgreSQL connection is deprecated')) {
            $deps++;
        }
        return true;
    }
    return false;
});

$fns = ['pg_host', 'pg_dbname', 'pg_port', 'pg_tty', 'pg_options', 'pg_client_encoding', 'pg_version', 'pg_ping'];
$errors = 0;
foreach ($fns as $f) {
    try {
        $f();
        echo "$f=fail\n";
    } catch (Error $e) {
        if ('No PostgreSQL connection opened yet' === $e->getMessage()) {
            $errors++;
        } else {
            echo "$f=badmsg\n";
        }
    }
}
try {
    pg_parameter_status('server_version');
    echo "parameter_status=fail\n";
} catch (Error $e) {
    if ('No PostgreSQL connection opened yet' === $e->getMessage()) {
        $errors++;
    }
}
echo 'deps=', $deps, "\n";
echo 'errors=', $errors, "\n";
?>
--EXPECT--
deps=9
errors=9
