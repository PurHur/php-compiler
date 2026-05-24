--TEST--
First-class callable syntax for instance methods (issue #1230)
--FILE--
<?php
class Greeter {
    public function greet() {
        return 'hello';
    }
}
$obj = new Greeter();
$call = $obj->greet(...);
echo $call(), "\n";
--EXPECT--
hello
