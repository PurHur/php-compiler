--TEST--
PDO::__construct/prepare/query Reflection names/arity match Zend stubs (#24590)
--ENV--
PHP_COMPILER_ENABLE_PDO_SQLITE=1
--FILE--
<?php
foreach (['__construct', 'prepare', 'query'] as $m) {
    $r = new ReflectionMethod('PDO', $m);
    $ns = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '(none)';
        $ns[] = $p->getName()
            .($p->isOptional() ? '?' : '')
            .($p->isVariadic() ? '...' : '')
            .':'.$t;
    }
    echo $m, ' req=', $r->getNumberOfRequiredParameters(),
        ' total=', $r->getNumberOfParameters(),
        ' [', implode(', ', $ns), "]\n";
}

$pdo = new PDO(dsn: 'sqlite::memory:');
$st = $pdo->prepare(query: 'SELECT 1 AS n');
echo get_class($st), "\n";
$rows = $pdo->query(query: 'SELECT 1 AS n')->fetchAll(PDO::FETCH_ASSOC);
echo $rows[0]['n'], "\n";

try {
    new PDO(dsn: 'sqlite::memory:', passwd: 'x');
    echo "passwd_accepted\n";
} catch (Error $e) {
    echo "passwd_rejected\n";
}
try {
    $pdo->prepare(statement: 'SELECT 1');
    echo "statement_accepted\n";
} catch (Error $e) {
    echo "statement_rejected\n";
}
?>
--EXPECT--
__construct req=1 total=4 [dsn:string, username?:?string, password?:?string, options?:?array]
prepare req=1 total=2 [query:string, options?:array]
query req=1 total=3 [query:string, fetchMode?:?int, fetchModeArgs?...:mixed]
PDOStatement
1
passwd_rejected
statement_rejected
