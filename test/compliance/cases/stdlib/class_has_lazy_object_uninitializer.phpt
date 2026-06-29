--TEST--
stdlib class_has_lazy_object_uninitializer() — lazy proxy probe (#6097)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip class_has_lazy_object_uninitializer requires PHP 8.4+');
}
?>
--FILE--
<?php
class Svc {
    public function __construct(public string $id = '') {}
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyProxy(static fn (): Svc => new Svc('proxy'));
var_export(class_has_lazy_object_uninitializer($lazy));
echo "\n";
var_export(class_has_lazy_object_uninitializer(new Svc('eager')));
echo "\n";
$lazy->id;
var_export(class_has_lazy_object_uninitializer($lazy));
echo "\n";
try {
    class_has_lazy_object_uninitializer(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
false
false
class_has_lazy_object_uninitializer(): Argument #1 ($object) must be of type object, int given
