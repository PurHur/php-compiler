--TEST--
Language: PHP 8.4 parent::$prop::get()/::set() parent hook call (#21296, Zend/zend_property_hooks.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks require PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Base {
    public string $name {
        get => 'BASE';
        set { }
    }
}
class Child extends Base {
    public string $name {
        get => parent::$name::get() . '+C';
        set { }
    }
}
echo (new Child())->name, "\n";

class BaseStore {
    public string $v = "";
    public string $name {
        get => $this->v;
        set => $this->v = $value;
    }
}
class ChildStore extends BaseStore {
    public string $name {
        get => parent::$name::get();
        set => parent::$name::set(strtoupper($value));
    }
}
$o = new ChildStore();
$o->name = 'hi';
echo $o->name, "\n";
--EXPECT--
BASE+C
HI
