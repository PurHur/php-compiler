--TEST--
stdlib array_udiff array_uintersect Reflection array+variadic rest (#23959)
--FILE--
<?php
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
echo json_encode(array_udiff([1, 2], [2], 'strcmp')), "\n";
?>
--EXPECT--
array_udiff n=2 req=1 [array,rest*]
array_udiff_assoc n=2 req=1 [array,rest*]
array_udiff_uassoc n=2 req=1 [array,rest*]
array_uintersect n=2 req=1 [array,rest*]
array_uintersect_assoc n=2 req=1 [array,rest*]
array_uintersect_uassoc n=2 req=1 [array,rest*]
[1]
