<?php
// #26223 — PDO::connect Reflection arity + named dsn (PROFILE=8.4).
$r = new ReflectionMethod('PDO', 'connect');
echo 'params=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    echo $p->getName(), ' opt=', $p->isOptional() ? 'y' : 'n', ' type=', $type, "\n";
}
try {
    PDO::connect(dsn: 'sqlite::memory:');
    echo "named_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
