<?php
// foreach-by-ref over uninitialized typed property must use Zend's by-ref Error message.
// Plain `$r = &$o->x` already matches (see #31771).

class C {
    public int $a;
}
$o = new C;
try {
    $r =& $o->a;
    echo "byref_ok\n";
} catch (Throwable $e) {
    echo 'byref:', $e->getMessage(), "\n";
}

class D {
    public array $a;
}
$d = new D;
try {
    foreach ($d->a as &$v) {
    }
    echo "foreach_ok\n";
} catch (Throwable $e) {
    echo 'foreach:', $e->getMessage(), "\n";
}
