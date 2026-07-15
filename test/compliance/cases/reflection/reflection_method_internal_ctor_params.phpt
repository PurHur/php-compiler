--TEST--
Stdlib: ReflectionMethod internal ctor param metadata — ArrayObject::__construct (#18338, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

$m = new ReflectionMethod('ArrayObject', '__construct');
echo 'param_count=', $m->getNumberOfParameters(), "\n";
echo 'required_count=', $m->getNumberOfRequiredParameters(), "\n";
$p0 = new ReflectionParameter(['ArrayObject', '__construct'], 0);
echo 'p0_name=', $p0->getName(), "\n";
echo 'p0_optional=', $p0->isOptional() ? 'yes' : 'no', "\n";
?>
--EXPECT--
param_count=3
required_count=0
p0_name=array
p0_optional=yes
