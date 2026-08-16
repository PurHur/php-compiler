--TEST--
ext/pgsql pg_connect/pg_pconnect Reflection connection_string/flags → PgSql\Connection|false (#27811)
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
foreach (['pg_connect', 'pg_pconnect'] as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        $t = $p->getType();
        echo $fn, ' ', $p->getName(), ':', $t ? (string) $t : '?', $p->isOptional() ? ' opt' : '', "\n";
    }
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
}
try {
    pg_connect(connection_string: 'host=127.0.0.1 port=1 connect_timeout=1', flags: 0);
    echo "named_ok\n";
} catch (Throwable $e) {
    echo 'named: ', get_class($e), "\n";
}
try {
    pg_connect(connect_type: 0);
} catch (Throwable $e) {
    echo 'legacy: ', get_class($e), "\n";
}
?>
--EXPECT--
pg_connect connection_string:string
pg_connect flags:int opt
pg_connect ret=PgSql\Connection|false
pg_pconnect connection_string:string
pg_pconnect flags:int opt
pg_pconnect ret=PgSql\Connection|false
named_ok
legacy: Error
