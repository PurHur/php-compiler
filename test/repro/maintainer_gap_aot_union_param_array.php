<?php
/**
 * #29859 — AOT int|string param + array must TypeError (not SIGABRT).
 * Zend/VM: TypeError: f(): Argument #1 ($x) must be of type string|int, array given
 */
function f(int|string $x) {
    return $x;
}
f([]);
