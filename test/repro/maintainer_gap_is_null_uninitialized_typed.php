<?php
/**
 * Repro for #10874 — is_null() on uninitialized typed property must Error (Zend/zend_execute.c).
 */
class C {
    public int $x;
    public ?int $y;
}

$c = new C();

$pass = true;

try {
    is_null($c->x);
    echo "FAIL: is_null(int uninit) returned\n";
    $pass = false;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

try {
    is_null($c->y);
    echo "FAIL: is_null(?int uninit) returned\n";
    $pass = false;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

$c->y = null;
echo is_null($c->y) ? "null-y\n" : "not-null-y\n";

exit($pass ? 0 : 1);
