--TEST--
Language: property hooks accepted on default 8.4.0-dev profile (#30204, re-#22371, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE');
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks not enabled on default profile');
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
