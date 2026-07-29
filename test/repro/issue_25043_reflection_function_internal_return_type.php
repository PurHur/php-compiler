<?php
declare(strict_types=1);

// #25043 / re-#22068: ReflectionFunction::hasReturnType/getReturnType for internals
// must use ReflectionSupport helpers (not throw Error) and leave the receiver usable.

$r = new ReflectionFunction('strlen');
try {
    var_export($r->hasReturnType());
    echo "\n";
    $t = $r->getReturnType();
    echo $t instanceof ReflectionNamedType ? $t->getName() : 'NULL', "\n";
    echo 'params=', count($r->getParameters()), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

foreach (['count', 'array_keys', 'is_string'] as $name) {
    $rf = new ReflectionFunction($name);
    $rt = $rf->getReturnType();
    echo $name, '=', $rf->hasReturnType() ? ($rt instanceof ReflectionNamedType ? $rt->getName() : '?') : 'none', "\n";
}
