<?php
/**
 * #27597 — array_first/array_last Reflection types (ext/standard/array.stub.php, PHP 8.5).
 * Requires PHP_COMPILER_PROFILE=8.5 (or stable 8.5+).
 */
foreach (['array_first', 'array_last'] as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        echo $fn, ' ', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
    }
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
