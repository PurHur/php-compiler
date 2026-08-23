--TEST--
AOT ReflectionClass/ReflectionObject $name and getName (#34001)
--FILE--
<?php
class A {}
$rc = new ReflectionClass('A');
echo $rc->name, '|', $rc->getName(), PHP_EOL;
$ro = new ReflectionObject(new A);
echo $ro->name, '|', $ro->getName(), PHP_EOL;
--EXPECT--
A|A
A|A
