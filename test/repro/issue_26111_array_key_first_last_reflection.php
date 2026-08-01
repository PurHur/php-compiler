<?php
/**
 * #26111 — array_key_first / array_key_last Reflection matches php-src
 * ext/standard/array.stub.php (array $array): string|int|null.
 */
foreach (['array_key_first', 'array_key_last'] as $fn) {
    $r = new ReflectionFunction($fn);
    $p = $r->getParameters()[0];
    echo $fn,
        ' typed=', $p->hasType() ? (string) $p->getType() : 'no',
        ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none',
        PHP_EOL;
}
echo 'first=', array_key_first(array: ['x' => 1, 'y' => 2]), PHP_EOL;
echo 'last=', array_key_last(array: ['x' => 1, 'y' => 2]), PHP_EOL;
