--TEST--
stdlib ReflectionFunction::getExtension() for internal and user functions (#22099)
--FILE--
<?php
$rf = new ReflectionFunction('strlen');
echo $rf->getExtension()?->getName(), "\n";
$closure = new ReflectionFunction(fn () => 1);
echo $closure->getExtension() === null ? 'null' : 'not-null', "\n";
?>
--EXPECT--
Core
null
