<?php
class C {
    public function __toString(): string { return '42'; }
}
class D {}

$c = new C();
$d = new D();
echo 'C_int:', (int) $c, "\n";
echo 'D_int:', (int) $d, "\n";
