--TEST--
Language: ReflectionClass::newLazyGhost defers constructor (#4026, #4823)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip ReflectionClass::newLazyGhost requires PHP 8.4+');
}
?>
--FILE--
<?php
class Svc {
    public function __construct(public string $id = '') {
        echo "init\n";
    }
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $object) {
    $object->__construct('x');
});
echo "before\n";
echo $lazy->id, "\n";
echo $lazy->id, "\n";
--EXPECT--
before
init
x
x
