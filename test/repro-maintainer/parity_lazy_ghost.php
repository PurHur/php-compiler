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
