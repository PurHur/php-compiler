--TEST--
First-class callable syntax for functions and static methods (issue #1230)
--FILE--
<?php
$fn = strlen(...);
echo $fn("hi"), "\n";

class Greeter {
    public static function greet() {
        return 'hello';
    }
}
$call = Greeter::greet(...);
echo $call(), "\n";
--EXPECT--
2
hello
