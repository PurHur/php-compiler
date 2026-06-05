--TEST--
Language: LazyGhostTrait built-in marker trait (#6096)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip LazyGhostTrait requires PHP 8.4+');
}
?>
--FILE--
<?php
var_export(trait_exists('LazyGhostTrait'));
echo "\n";
class Svc {
    use LazyGhostTrait;
    public string $id = '';
    public function __construct(string $id = '') {
        $this->id = $id;
    }
}
echo "compiled\n";
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) {
    $o->__construct('lazy');
});
echo $ref->isUninitializedLazyObject($lazy) ? 'uninit' : 'init', "\n";
echo $lazy->id, "\n";
--EXPECT--
true
compiled
uninit
lazy
