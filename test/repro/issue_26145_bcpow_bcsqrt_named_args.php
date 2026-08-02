<?php
/** Repro guard for #26145 — bcpow/bcsqrt Zend stub names under PROFILE=8.4. */
$r = new ReflectionFunction('bcpow');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
echo bcpow(num: '2', exponent: '3'), "\n";
try {
    echo bcpow(x: '2', y: '3'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$r2 = new ReflectionFunction('bcsqrt');
echo 'bcsqrt=', implode(',', array_map(static fn ($p) => $p->getName(), $r2->getParameters())), "\n";
echo bcsqrt(num: '9'), "\n";
try {
    echo bcsqrt(operand: '9'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
