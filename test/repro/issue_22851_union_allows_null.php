<?php
// Issue #22851 — ReflectionUnionType::allowsNull for int|string (php-src-strict)
function a(int|string $x) {}
function b(?int $x) {}
function c(int|string|null $x) {}
foreach (['a', 'b', 'c'] as $fn) {
    $t = (new ReflectionFunction($fn))->getParameters()[0]->getType();
    echo $fn, ' allowsNull=', (int) $t->allowsNull(), "\n";
}
