--TEST--
ext/pgsql pg_query/pg_fetch_*/pg_close Reflection matches Zend stubs (#28782)
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
foreach (['pg_query', 'pg_fetch_assoc', 'pg_fetch_row', 'pg_close'] as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        $t = $p->getType();
        echo $fn, ' ', $p->getName(), ':', $t ? (string) $t : '?', $p->isOptional() ? ' opt' : '', "\n";
    }
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
}
try {
    pg_query(connection: null, query: 'SELECT 1');
    echo "named_query_ok\n";
} catch (Throwable $e) {
    echo 'named_query: ', get_class($e), "\n";
}
try {
    pg_fetch_assoc(result: null, row: null);
    echo "named_fetch_ok\n";
} catch (Throwable $e) {
    echo 'named_fetch: ', get_class($e), "\n";
}
try {
    pg_fetch_row(result: null, row: null, mode: 2);
    echo "named_row_ok\n";
} catch (Throwable $e) {
    echo 'named_row: ', get_class($e), "\n";
}
try {
    pg_close(connection: null);
    echo "named_close_ok\n";
} catch (Throwable $e) {
    echo 'named_close: ', get_class($e), "\n";
}
try {
    pg_fetch_row(result_type: 2);
} catch (Throwable $e) {
    echo 'legacy_result_type: ', get_class($e), "\n";
}
?>
--EXPECT--
pg_query connection:?
pg_query query:string opt
pg_query ret=PgSql\Result|false
pg_fetch_assoc result:PgSql\Result
pg_fetch_assoc row:?int opt
pg_fetch_assoc ret=array|false
pg_fetch_row result:PgSql\Result
pg_fetch_row row:?int opt
pg_fetch_row mode:int opt
pg_fetch_row ret=array|false
pg_close connection:?PgSql\Connection opt
pg_close ret=bool
named_query: TypeError
named_fetch: ArgumentCountError
named_row: ArgumentCountError
named_close: TypeError
legacy_result_type: Error
