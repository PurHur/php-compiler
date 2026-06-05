--TEST--
Language: ReflectionClass lazy object introspection (#5968, PHP 8.4 zend_lazy_objects.c)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip lazy object introspection requires PHP 8.4+');
}
?>
--FILE--
<?php
class Svc {
    public string $id = '';
    public function __construct(string $tag = '') {
        $this->id = $tag;
    }
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) {
    $o->__construct('init');
});
echo null === $ref->getLazyInitializer($lazy) ? 'no' : 'yes', "\n";
$ref->markLazyObjectAsInitialized($lazy);
echo $lazy->id, "\n";
echo null === $ref->getLazyInitializer($lazy) ? 'cleared' : 'still', "\n";
$plain = new Svc('p');
$ref->resetAsLazyGhost($plain, function (Svc $o) {
    $o->__construct('r');
});
echo $plain->id, "\n";
--EXPECT--
yes

cleared
r
