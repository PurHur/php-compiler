--TEST--
Stdlib: class_uses_recursive() — nested trait uses (VM, #6469)
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
echo isset($direct['B']) ? '1' : '0';
echo isset($direct['A']) ? '1' : '0';
echo isset($recursive['A']) ? '1' : '0';
echo isset($recursive['B']) ? '1' : '0';
echo class_uses_recursive('MissingClass') ? '1' : '0';
echo "\n";
--EXPECT--
10110
