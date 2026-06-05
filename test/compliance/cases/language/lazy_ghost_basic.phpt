--TEST--
Language: ReflectionClass::newLazyGhost basic ghost init (#6068)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip ReflectionClass::newLazyGhost requires PHP 8.4+');
}
?>
--FILE--
<?php
class Data {
    public string $name = 'unset';
    public function __construct() { throw new Exception('eager'); }
}
$rc = new ReflectionClass(Data::class);
var_export(method_exists($rc, 'newLazyGhost'));
echo "\n";
$obj = $rc->newLazyGhost(function (Data $o) {
    $o->name = 'lazy';
});
echo $rc->isUninitializedLazyObject($obj) ? 'uninit' : 'init', "\n";
echo $obj->name, "\n";
echo $rc->isUninitializedLazyObject($obj) ? 'uninit' : 'init', "\n";
echo $obj->name, "\n";
--EXPECT--
true
uninit
lazy
init
lazy
