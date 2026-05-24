--TEST--
First-class callable syntax (JIT, issue #1363)
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
