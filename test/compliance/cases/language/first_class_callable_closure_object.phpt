--TEST--
First-class callable must be a Closure object (issue #4810)
--FILE--
<?php
declare(strict_types=1);
$f = strlen(...);
var_export(is_object($f));
echo PHP_EOL;
var_export($f instanceof Closure);
echo PHP_EOL;
echo $f('abc'), PHP_EOL;

$slice = array_slice(...);
var_export(is_object($slice));
echo PHP_EOL;
var_export($slice instanceof Closure);
echo PHP_EOL;
echo count($slice(['a', 'b', 'c'], 1)), PHP_EOL;

class Greeter {
    public static function greet(): string {
        return 'hello';
    }
}
$call = Greeter::greet(...);
var_export($call instanceof Closure);
echo PHP_EOL;
echo $call(), PHP_EOL;
--EXPECT--
true
true
3
true
true
2
true
hello
