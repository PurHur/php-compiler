<?php
// Issue #25622 — typed property assign-via-ref TypeError message (zend_execute.c)

class A
{
    public int $x = 1;
    public ?int $y = 1;
}

$a = new A();
$r = &$a->x;
try {
    $r = 'hi';
    echo "int via-ref: no error\n";
} catch (Throwable $e) {
    echo 'int via-ref: ', $e->getMessage(), "\n";
}

try {
    $a->x = 'hi';
    echo "int direct: no error\n";
} catch (Throwable $e) {
    echo 'int direct: ', $e->getMessage(), "\n";
}

$r2 = &$a->y;
try {
    $r2 = 'hi';
    echo "nullable via-ref: no error\n";
} catch (Throwable $e) {
    echo 'nullable via-ref: ', $e->getMessage(), "\n";
}

try {
    $a->y = 'hi';
    echo "nullable direct: no error\n";
} catch (Throwable $e) {
    echo 'nullable direct: ', $e->getMessage(), "\n";
}

class B
{
    public static int $s = 1;
}

$rs = &B::$s;
try {
    $rs = 'hi';
    echo "static via-ref: no error\n";
} catch (Throwable $e) {
    echo 'static via-ref: ', $e->getMessage(), "\n";
}

try {
    B::$s = 'hi';
    echo "static direct: no error\n";
} catch (Throwable $e) {
    echo 'static direct: ', $e->getMessage(), "\n";
}
