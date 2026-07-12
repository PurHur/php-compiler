--TEST--
Language: PHP 8.4 explicit virtual property hooks + parent::$prop->get() (#18170, zend_language_parser.y)
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
class Base {
    public virtual string $x {
        get => 'base';
    }
}

class Child extends Base {
    public virtual string $x {
        get => parent::$x->get() . '-child';
    }
}

echo (new Base())->x, "\n";
echo (new Child())->x, "\n";
echo "PASS_VIRTUAL_PROPERTY_HOOKS\n";
--EXPECT--
base
base-child
PASS_VIRTUAL_PROPERTY_HOOKS
