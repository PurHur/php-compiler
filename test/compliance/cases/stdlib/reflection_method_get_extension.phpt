--TEST--
stdlib ReflectionMethod::getExtension() on internal methods (#22100)
--FILE--
<?php
$rm = new ReflectionMethod(DateTime::class, 'format');
echo $rm->getExtension()?->getName(), "\n";
?>
--EXPECT--
date
