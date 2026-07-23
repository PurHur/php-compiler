--TEST--
Language: ReflectionClass::newLazyGhost instance invoke (#6399, #22527)
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
var_export(method_exists(ReflectionClass::class, 'newLazyGhost'));
echo "\n";
$rc = new ReflectionClass(Data::class);
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
