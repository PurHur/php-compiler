--TEST--
stdlib ReflectionClass::getExtension() returns ReflectionExtension for internal classes (#11462)
--FILE--
<?php
$rc = new ReflectionClass(stdClass::class);
$ext = $rc->getExtension();
echo class_exists('ReflectionExtension') ? 'class=yes' : 'class=no', "\n";
echo $ext instanceof ReflectionExtension ? 'instance=yes' : 'instance=no', "\n";
echo $ext->getName(), "\n";
?>
--EXPECT--
class=yes
instance=yes
Core
