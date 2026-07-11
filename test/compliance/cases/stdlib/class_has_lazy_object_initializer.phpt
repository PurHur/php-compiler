--TEST--
stdlib class_has_lazy_object_initializer() — lazy ghost probe (#6052)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip class_has_lazy_object_initializer requires PHP 8.4+');
}
?>
--FILE--
<?php
class Svc { public string $id = ''; }
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) { $o->id = 'x'; });
var_export(class_has_lazy_object_initializer($lazy));
echo "\n";
var_export(class_has_lazy_object_initializer(new Svc()));
echo "\n";
$ref->markLazyObjectAsInitialized($lazy);
var_export(class_has_lazy_object_initializer($lazy));
echo "\n";
try {
    class_has_lazy_object_initializer(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
false
false
class_has_lazy_object_initializer(): Argument #1 ($object) must be of type object, int given
