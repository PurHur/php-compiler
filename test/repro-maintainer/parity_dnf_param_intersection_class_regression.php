<?php
// Maintainer repro for #6741 — (I1&I2)|C DNF parameter must compile (TypeReconstructor Intersection).
interface I1 {}
interface I2 {}
class C implements I1, I2 {}

class DnfParam {
    public function m((I1&I2)|C $x): void {
        echo get_class($x), "\n";
    }
}

(new DnfParam())->m(new C());
