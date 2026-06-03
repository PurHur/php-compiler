--TEST--
Language: ReflectionClass::newLazyProxy defers constructor (#3317, #4823)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip ReflectionClass::newLazyProxy requires PHP 8.4+');
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
$lazy = $ref->newLazyProxy(static fn () => new Svc('x'));
echo "before\n";
echo $lazy->id, "\n";
echo $lazy->id, "\n";
--EXPECT--
before
init
x
x
