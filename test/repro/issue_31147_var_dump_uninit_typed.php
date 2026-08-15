<?php
/** Repro #31147 — var_dump uninitialized typed properties (php-src ext/standard/var.c). */
class C {
    public int $x;
    public string $s;
    public $y = 1;
}
var_dump(new C);
