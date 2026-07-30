<?php
/**
 * Issue 23959 — array_udiff / array_uintersect Reflection: array + variadic rest
 * (php-src ext/standard/array.stub.php).
 */
$fns = [
    'array_udiff',
    'array_udiff_assoc',
    'array_udiff_uassoc',
    'array_uintersect',
    'array_uintersect_assoc',
    'array_uintersect_uassoc',
];
foreach ($fns as $fn) {
    $r = new ReflectionFunction($fn);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $parts[] = $p->getName().($p->isVariadic() ? '*' : '');
    }
    echo $fn, ' n=', $r->getNumberOfParameters(),
        ' req=', $r->getNumberOfRequiredParameters(),
        ' [', implode(',', $parts), "]\n";
}
echo 'udiff=', json_encode(array_udiff([1, 2, 3], [2], 'strcmp')), "\n";
