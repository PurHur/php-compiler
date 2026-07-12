--TEST--
AOT: ReflectionMethod::$class and $name public properties (#18298, ext/reflection/php_reflection.c)
--FILE--
<?php
$m = new ReflectionMethod('ArrayObject', 'offsetExists');
echo $m->class, "\n";
echo $m->name, "\n";
--EXPECT--
ArrayObject
offsetExists
