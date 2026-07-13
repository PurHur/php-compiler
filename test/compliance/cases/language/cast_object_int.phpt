--TEST--
Language: (int) cast on object — Warning + int(1), no __toString (#18444, zend_operators.c)
--FILE--
<?php
class C {
    public function __toString(): string { return '42'; }
}
class D {}

$c = new C();
$d = new D();
echo 'C_int:', (int) $c, "\n";
echo 'D_int:', (int) $d, "\n";
?>
--EXPECT--
C_int:1
D_int:1
