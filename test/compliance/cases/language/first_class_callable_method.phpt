--TEST--
First-class callable syntax for instance methods (issue #1230, #3566)
--FILE--
<?php
class Greeter {
    public function greet() {
        return 'hello';
    }

    public function add(int $a, int $b): int {
        return $a + $b;
    }
}
$obj = new Greeter();
$call = $obj->greet(...);
echo $call(), "\n";
$add = $obj->add(...);
echo $add(2, 3), "\n";
--EXPECT--
hello
5
