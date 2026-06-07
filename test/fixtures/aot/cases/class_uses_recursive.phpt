--TEST--
AOT class_uses_recursive() — nested trait uses (issue #6469)
--FILE--
<?php
trait A {}
trait B {
    use A;
}
class C {
    use B;
}
$direct = class_uses(C::class);
$recursive = class_uses_recursive(C::class);
$byObject = class_uses_recursive(new C());
echo isset($direct['B']) ? '1' : '0';
echo isset($direct['A']) ? '1' : '0';
echo isset($recursive['A']) ? '1' : '0';
echo isset($recursive['B']) ? '1' : '0';
echo isset($byObject['A']) ? '1' : '0';
echo isset($byObject['B']) ? '1' : '0';
--EXPECT--
101011
