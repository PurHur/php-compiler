--TEST--
Language: ReflectionClass kind API — isInterface/isTrait/getModifiers (#18335)
--FILE--
<?php
declare(strict_types=1);

var_export((new ReflectionClass('Iterator'))->isInterface());
echo "\n";
var_export((new ReflectionClass('Stringable'))->isTrait());
echo "\n";
trait ProbeTrait {}
var_export((new ReflectionClass('ProbeTrait'))->isTrait());
echo "\n";
echo (new ReflectionClass('stdClass'))->getModifiers(), "\n";
var_export((new ReflectionClass('Closure'))->isFinal());
echo "\n";
echo (new ReflectionClass('Closure'))->getModifiers(), "\n";
?>
--EXPECT--
true
false
true
0
true
32
