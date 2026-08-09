--TEST--
Language: var_dump/print_r DEBUG purpose — backing only, omit virtual (#29379, Zend/zend_property_hooks.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks disabled on reference profile');
}
?>
--FILE--
<?php
class VirtualGet {
    public $x {
        get => 42;
    }
}
class BackedGet {
    public $x = 1 {
        get => $this->x + 100;
    }
}
$v = new VirtualGet;
$b = new BackedGet;
var_dump($v);
print_r($v);
var_dump($b);
print_r($b);
debug_zval_dump($b);
--EXPECTF--
object(VirtualGet)#%d (0) {
}
VirtualGet Object
(
)
object(BackedGet)#%d (1) {
  ["x"]=>
  int(1)
}
BackedGet Object
(
    [x] => 1
)
object(BackedGet)#%d (1) refcount(%d){
  ["x"]=>
  int(1)
}
