--TEST--
Property hooks get-only short syntax on PHP 8.4 profile (#13904, Zend/zend_property_hooks.c)
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
class C {
    public string $greeting {
        get => 'hello';
    }
}
$c = new C();
echo $c->greeting, "\n";
--EXPECT--
hello
