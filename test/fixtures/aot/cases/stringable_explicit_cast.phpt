--TEST--
AOT (string) cast of Stringable calls __toString (#26821)
--FILE--
<?php
class A implements Stringable {
    public function __toString(): string { return "S"; }
}
echo (string)(new A()), "\n";
$a = new A();
echo (string)$a, "\n";
--EXPECT--
S
S
