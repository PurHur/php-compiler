--TEST--
First-class callable syntax for functions and static methods (issue #1230)
--FILE--
<?php
$c = strlen(...);
echo $c('abc'), "\n";

class Greeter {
    public static function greet() {
        return 'hello';
    }

    public static function viaSelf(): string {
        $f = self::greet(...);

        return $f();
    }
}
$call = Greeter::greet(...);
echo $call(), "\n";
echo Greeter::viaSelf(), "\n";

--EXPECT--
3
hello
hello
