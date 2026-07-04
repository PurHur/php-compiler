--TEST--
stdlib array_map() invokable object callback (#16228, ext/standard/array.c)
--FILE--
<?php
class Doubler {
    public function __invoke(int $x): int {
        return $x * 2;
    }
}
var_export(array_map(new Doubler(), [1, 2]));
echo "\n";
class Adder {
    public function __invoke(int $a, int $b): int {
        return $a + $b;
    }
}
$adder = new Adder();
var_export(array_map($adder, [1, 2], [10, 20]));
echo "\n";
--EXPECT--
array (
  0 => 2,
  1 => 4,
)
array (
  0 => 11,
  1 => 22,
)
