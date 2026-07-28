<?php
// Repro #23948 — get_parent_class() arity 1 under PROFILE=8.4 (no phantom allow_string).
class A {}
class B extends A {}

$r = new ReflectionFunction('get_parent_class');
echo 'argc=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), "\n";
}

try {
    var_export(get_parent_class(B::class, false));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    var_export(get_parent_class(object_or_class: B::class, allow_string: false));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

echo get_parent_class(B::class), "\n";
