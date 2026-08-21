<?php
/**
 * #28314 — image_type_to_extension Reflection return string|false
 * (ext/standard/image.stub.php / image.c).
 */
$r = new ReflectionFunction('image_type_to_extension');
echo 'image_type_to_extension=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
echo 'value0=', var_export(image_type_to_extension(0), true), "\n";
echo 'value2=', var_export(image_type_to_extension(2), true), "\n";
