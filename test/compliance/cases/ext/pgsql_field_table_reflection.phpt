--TEST--
ext/pgsql pg_field_table Reflection result/field → string|int|false (#27703)
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
$r = new ReflectionFunction('pg_field_table');
foreach ($r->getParameters() as $p) {
    $t = $p->getType();
    echo $p->getName(), ':', $t ? (string) $t : '?', $p->isOptional() ? ' opt' : '', "\n";
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
try {
    pg_field_table(result: 'x', field: 0);
} catch (Throwable $e) {
    echo 'field: ', get_class($e), "\n";
}
try {
    pg_field_table(result: 'x', field_number: 0);
} catch (Throwable $e) {
    echo 'field_number: ', get_class($e), "\n";
}
?>
--EXPECT--
result:?PgSql\Result
field:int
oid_only:bool opt
ret=string|int|false
field: TypeError
field_number: Error
