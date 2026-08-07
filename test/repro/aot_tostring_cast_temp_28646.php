<?php
/** Repro #28646 — AOT (string) cast of temp with __toString (not via named local). */
class C {
    public function __toString(): string { return "x"; }
}
class A implements Stringable {
    public function __toString(): string { return "s"; }
}
echo (string)(new C), "\n";
echo (string)(new A), "\n";
$o = new C;
echo (string)$o, "\n";
echo new C, "\n";
