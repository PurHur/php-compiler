<?php
// Repro for #26795 — WeakReference::create/get under AOT (php-src-strict).
// Function scope: AOT {main} unset skips delref. Avoid get()-before-unset so
// expression-temp refs do not keep the referent alive. Avoid var_dump (#23540).
class Box
{
    public $x = 1;
}
function wr_probe(): void
{
    $o = new Box();
    $r = WeakReference::create($o);
    echo is_object($r) ? "1\n" : "0\n";
    unset($o);
    echo ($r->get() === null) ? "null\n" : "obj\n";
}
wr_probe();
