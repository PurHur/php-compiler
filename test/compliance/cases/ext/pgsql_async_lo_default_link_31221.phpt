--TEST--
ext/pgsql omitted-connection async/lo/trace FETCH_DEFAULT_LINK DEP+Error (#31221)
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
    if ((E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno)
        && str_contains($errstr, 'Automatic fetching of PostgreSQL connection is deprecated')) {
        $deps++;
        return true;
    }
    return false;
});

$cases = [
    static fn () => pg_end_copy(),
    static fn () => pg_untrace(),
    static fn () => pg_put_line('x'),
    static fn () => pg_lo_create(),
    static fn () => pg_lo_unlink(1),
    static fn () => pg_lo_import('/tmp/x'),
    static fn () => pg_lo_export(1, '/tmp/x'),
    static fn () => pg_trace('/tmp/pgtrace.out'),
];
$errors = 0;
foreach ($cases as $fn) {
    try {
        $fn();
    } catch (Error $e) {
        if ('No PostgreSQL connection opened yet' === $e->getMessage()) {
            $errors++;
        }
    }
}
echo 'deps=', $deps, "\n";
echo 'errors=', $errors, "\n";
?>
--EXPECT--
deps=8
errors=8
