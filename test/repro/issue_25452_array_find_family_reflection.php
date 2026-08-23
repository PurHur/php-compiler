<?php
/**
 * #25452 — array_find family Reflection types (ext/standard/array.stub.php, PHP 8.4).
 * Requires PHP_COMPILER_PROFILE=8.4 (or stable 8.4+).
 */
foreach (['array_find', 'array_find_key', 'array_any', 'array_all'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : 'NONE';
        $ps[] = $p->getName() . ':' . $t;
    }
    $ret = $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE';
    echo $fn, ' params=', implode(',', $ps), ' ret=', $ret, "\n";
}
