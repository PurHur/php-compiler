--TEST--
stdlib lazy object introspection — property-read materialize flips ReflectionClass probe (#18818, #28517)
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
echo $ref->isUninitializedLazyObject($ghost) ? 'uninit:yes' : 'uninit:no';
echo "\n";
echo $ghost->id, "\n";
echo $ref->isUninitializedLazyObject($ghost) ? 'uninit:yes' : 'uninit:no';
echo "\n";
--EXPECT--
uninit:yes
ghost
uninit:no
