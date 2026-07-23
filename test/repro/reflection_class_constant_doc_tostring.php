<?php
/**
 * Repro for #22419 — ReflectionClassConstant getDocComment / __toString.
 */
class T {
    /** doc for X */
    public const X = 1;
    protected const Y = 'hi';
    private const Z = 3.5;
    final public const F = 9;
}

$r = new ReflectionClassConstant(T::class, 'X');
var_export($r->getDocComment());
echo "\n";
echo (string) $r;
foreach (['Y', 'Z', 'F'] as $n) {
    echo (string) (new ReflectionClassConstant(T::class, $n));
}
$nodoc = new ReflectionClassConstant(T::class, 'Y');
var_export($nodoc->getDocComment());
echo "\n";
