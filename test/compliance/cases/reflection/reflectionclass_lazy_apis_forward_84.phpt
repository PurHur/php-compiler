--TEST--
ReflectionClass lazy-object APIs present on PROFILE=8.4 (#25503, Zend/zend_lazy_objects.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Data {
    public string $name = 'unset';
    public function __construct() { throw new Exception('eager'); }
}
$r = new ReflectionClass(Data::class);
foreach ([
    'newLazyGhost',
    'newLazyProxy',
    'isUninitializedLazyObject',
    'resetAsLazyGhost',
    'getLazyInitializer',
] as $method) {
    echo $method, '=', method_exists($r, $method) ? '1' : '0', "\n";
}
$obj = $r->newLazyGhost(function (Data $o) {
    $o->name = 'lazy';
});
echo $r->isUninitializedLazyObject($obj) ? 'uninit' : 'init', "\n";
echo $obj->name, "\n";
echo $r->isUninitializedLazyObject($obj) ? 'uninit' : 'init', "\n";
--EXPECT--
newLazyGhost=1
newLazyProxy=1
isUninitializedLazyObject=1
resetAsLazyGhost=1
getLazyInitializer=1
uninit
lazy
init
