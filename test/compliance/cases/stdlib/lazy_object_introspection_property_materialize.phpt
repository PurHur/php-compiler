--TEST--
stdlib lazy object introspection — property-read materialize flips probes (#18818, zend_lazy_objects.c)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip lazy object introspection materialize requires PHP 8.4+');
}
?>
--FILE--
<?php
class Svc { public string $id = ''; }
$ref = new ReflectionClass(Svc::class);
$ghost = $ref->newLazyGhost(function (Svc $o) { $o->id = 'ghost'; });
echo class_has_lazy_object_initializer($ghost) ? 'init:yes' : 'init:no';
echo "\n";
echo $ref->isUninitializedLazyObject($ghost) ? 'uninit:yes' : 'uninit:no';
echo "\n";
echo $ghost->id, "\n";
echo class_has_lazy_object_initializer($ghost) ? 'init:yes' : 'init:no';
echo "\n";
echo $ref->isUninitializedLazyObject($ghost) ? 'uninit:yes' : 'uninit:no';
echo "\n";
--EXPECT--
init:yes
uninit:yes
ghost
init:no
uninit:no
