--TEST--
AOT (string) cast of temp with __toString (not only Stringable / named local) (#28646)
--FILE--
<?php
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
--EXPECT--
x
s
x
