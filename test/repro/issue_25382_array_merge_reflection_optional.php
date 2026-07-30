<?php
// array_merge Reflection: variadic $arrays is optional (array_merge() => []).
// php-src: ext/standard/array.stub.php — function array_merge(array ...$arrays): array
foreach (['array_merge', 'array_merge_recursive'] as $fn) {
    $p = (new ReflectionFunction($fn))->getParameters()[0];
    echo $fn, ' name=', $p->getName(),
        ' opt=', (int) $p->isOptional(),
        ' variadic=', (int) $p->isVariadic(),
        ' type=', $p->hasType() ? (string) $p->getType() : 'NONE',
        ' required=', (new ReflectionFunction($fn))->getNumberOfRequiredParameters(),
        "\n";
}
var_export(array_merge());
echo "\n";
var_export(array_merge([1]));
echo "\n";
var_export(array_merge_recursive());
echo "\n";
