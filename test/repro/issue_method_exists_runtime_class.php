<?php
// Repro #32701 — AOT method_exists() on a class string (NestedJIT bool ABI).
class C {
    public function foo() {}
}
var_dump(method_exists('C', 'foo'));
$n = 'C';
var_dump(method_exists($n, 'foo'));
