--TEST--
ext/pgsql PHP 8.5 helpers withheld on PROFILE=8.4 (#26191)
--ENV--
PHP_COMPILER_ENABLE_PGSQL=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
// Soft-exit: BaseTest ignores --SKIPIF--.
if (!function_exists('pg_connect')) {
    echo "skip\n";
    exit(0);
}
foreach (['pg_close_stmt', 'pg_service'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'Y' : 'N', "\n";
}
?>
--EXPECT--
pg_close_stmt=N
pg_service=N
