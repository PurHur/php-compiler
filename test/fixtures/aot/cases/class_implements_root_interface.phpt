--TEST--
AOT class_implements() on root interface — parent map only (#7400)
--FILE--
<?php
interface I {}
interface J extends I {
    public function m(): void;
}
class C implements I {}
class D implements J {
    public function m(): void {}
}
$root = class_implements('I');
echo count($root) . "\n";

$child = class_implements('J');
echo count($child) . "\n";
echo isset($child['I']) ? '1' : '0';
echo isset($child['J']) ? '1' : '0';
--EXPECT--
0
1
10
