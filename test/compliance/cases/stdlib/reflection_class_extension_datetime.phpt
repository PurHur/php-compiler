--TEST--
stdlib ReflectionClass::getExtension* on date classes reports date extension (#22098)
--FILE--
<?php
$rc = new ReflectionClass(DateTime::class);
echo $rc->getExtensionName(), "\n";
echo $rc->getExtension()->getName(), "\n";
$rci = new ReflectionClass(DateTimeImmutable::class);
echo $rci->getExtensionName(), "\n";
$std = new ReflectionClass(stdClass::class);
echo $std->getExtensionName(), "\n";
?>
--EXPECT--
date
date
date
Core
