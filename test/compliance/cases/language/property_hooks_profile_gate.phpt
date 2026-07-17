--TEST--
Language: property hooks on default 8.4.0-dev profile (#19952, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE');
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip default profile does not enable property hooks');
}
?>
--FILE--
<?php
class User {
    public string $name {
        set(string $value) { $this->name = ucfirst($value); }
        get => $this->name;
    }
}
$u = new User();
$u->name = "alice";
echo $u->name . "\n";
--EXPECT--
Alice
