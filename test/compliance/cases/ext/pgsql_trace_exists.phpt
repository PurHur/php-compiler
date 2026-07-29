--TEST--
ext/pgsql pg_trace/pg_untrace exist when libpq advertises (#20574)
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
foreach (['pg_trace', 'pg_untrace'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
echo 'no_default=', (int) @pg_trace('/tmp/phpc_pg_trace_none.log'), "\n";
?>
--EXPECT--
pg_trace=1
pg_untrace=1
no_default=0
