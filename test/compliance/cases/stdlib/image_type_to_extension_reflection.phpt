--TEST--
image_type_to_extension Reflection return string|false (VM, issue #28314, image.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('image_type_to_extension');
echo 'image_type_to_extension=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
echo 'value0=', var_export(image_type_to_extension(0), true), "\n";
echo 'value2=', var_export(image_type_to_extension(2), true), "\n";
?>
--EXPECT--
image_type_to_extension=string|false
value0=false
value2='.jpeg'
