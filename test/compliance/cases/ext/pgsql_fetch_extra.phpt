--TEST--
ext/pgsql pg_fetch_array/object/result + free/seek (#20704)
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
foreach (['pg_fetch_array', 'pg_fetch_object', 'pg_fetch_result', 'pg_free_result', 'pg_result_seek'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
?>
--EXPECT--
pg_fetch_array=1
pg_fetch_object=1
pg_fetch_result=1
pg_free_result=1
pg_result_seek=1
