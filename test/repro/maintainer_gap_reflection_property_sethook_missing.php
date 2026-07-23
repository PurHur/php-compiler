<?php
// Repro for #22494 — setHook must stay absent (Zend 8.4+/8.5 have getHook/getHooks only).
class T {
    public string $x {
        get => 'a';
        set {}
    }
}
$rp = new ReflectionProperty(T::class, 'x');
echo method_exists($rp, 'setHook') ? "setHook yes\n" : "setHook no\n";
echo method_exists($rp, 'getHook') ? "getHook yes\n" : "getHook no\n";
