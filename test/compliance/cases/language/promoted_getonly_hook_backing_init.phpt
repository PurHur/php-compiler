--TEST--
Language: promoted get-only hooked property initializes backing (#29674, zend_object_handlers.c)
--DESCRIPTION--
Constructor-promoted get-only hooks that reference $this->prop are backed. Zend writes the
ctor argument into the backing store without a set hook; get still runs on read. Virtual
get-only (no $this->prop) remains read-only. Later backed writes also use default store.
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4 property hooks gate');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public function __construct(
        public string $name {
            get => strtoupper($this->name);
        }
    ) {}
}
$c = new C('hi');
echo $c->name, "\n";
$c->name = 'z';
echo $c->name, "\n";

class V {
    public function __construct(
        public string $name {
            get => 'CONST';
        }
    ) {}
}
try {
    new V('hi');
    echo "virtual_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
HI
Z
Property V::$name is read-only
