--TEST--
Language: PHP 8.4 property hooks `{ get =>; set => }` block syntax (#15561, Zend/zend_property_hooks.c)
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
declare(strict_types=1);

class C {
    public string $name {
        get => strtoupper($this->name);
        set => $this->name = $value;
    }
}

$c = new C();
$c->name = 'Hello';
echo $c->name, "\n";
--EXPECT--
HELLO
