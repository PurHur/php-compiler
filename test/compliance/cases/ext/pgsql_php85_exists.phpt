--TEST--
ext/pgsql PHP 8.5 helpers exist without live Postgres (#26191)
--ENV--
PHP_COMPILER_ENABLE_PGSQL=1
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);
// Soft-exit: BaseTest ignores --SKIPIF--.
if (!function_exists('pg_connect')) {
    echo "skip\n";
    exit(0);
}
foreach (['pg_close_stmt', 'pg_service'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
// Arity / empty-name guards (no live server required).
try {
    pg_close_stmt();
    echo "close_arity=fail\n";
} catch (ArgumentCountError $e) {
    echo "close_arity=ok\n";
}
try {
    pg_service(1, 2);
    echo "service_arity=fail\n";
} catch (ArgumentCountError $e) {
    echo "service_arity=ok\n";
}
?>
--EXPECT--
pg_close_stmt=1
pg_service=1
close_arity=ok
service_arity=ok
