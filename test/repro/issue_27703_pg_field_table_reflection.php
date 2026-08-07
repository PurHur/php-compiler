<?php
/**
 * #27703 — pg_field_table() Reflection: result/field/oid_only → string|int|false
 * (ext/pgsql/pgsql.stub.php). Requires PHP_COMPILER_ENABLE_PGSQL=1 when host lacks ext/pgsql.
 */
$r = new ReflectionFunction('pg_field_table');
foreach ($r->getParameters() as $p) {
    $t = $p->getType();
    echo $p->getName(), ':', $t ? (string) $t : '?', $p->isOptional() ? ' opt' : '', "\n";
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
try {
    pg_field_table(result: 'x', field: 0);
} catch (Throwable $e) {
    echo 'field: ', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    pg_field_table(result: 'x', field_number: 0);
} catch (Throwable $e) {
    echo 'field_number: ', get_class($e), ':', $e->getMessage(), "\n";
}
