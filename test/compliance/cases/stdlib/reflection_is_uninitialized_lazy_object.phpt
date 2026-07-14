--TEST--
ReflectionClass::isUninitializedLazyObject() — lazy ghost state probe (#6054, #18818 property materialize)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip isUninitializedLazyObject requires PHP 8.4+');
}
?>
--FILE--
<?php
class Svc { public string $id = ''; }
class Other {}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) { $o->id = 'x'; });
var_export($ref->isUninitializedLazyObject($lazy));
echo "\n";
var_export($ref->isUninitializedLazyObject(new Svc()));
echo "\n";
$ref->markLazyObjectAsInitialized($lazy);
var_export($ref->isUninitializedLazyObject($lazy));
echo "\n";
$lazy2 = $ref->newLazyGhost(function (Svc $o) { $o->id = 'y'; });
$lazy2->id;
var_export($ref->isUninitializedLazyObject($lazy2));
echo "\n";
try {
    $ref->isUninitializedLazyObject(new Other());
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
false
false
false
ReflectionClass::isUninitializedLazyObject(): Argument #1 ($object) must be an instance of Svc, Other given
