--TEST--
print_r()/var_dump() dump backing / omit virtual hooked props — no get invoke (#29379, re-#6604, zend_property_hooks.c)
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
class Backed {
    public string $title {
        get => 'hook:' . ($this->title ?? '');
        set => $this->title = $value;
    }
}
$o = new Backed();
$o->title = 'x';
print_r($o);
var_dump($o);

class Virtual {
    public $x {
        get => 42;
    }
}
print_r(new Virtual);
var_dump(new Virtual);
--EXPECTF--
Backed Object
(
    [title] => x
)
object(Backed)#%d (1) {
  ["title"]=>
  string(1) "x"
}
Virtual Object
(
)
object(Virtual)#%d (0) {
}
