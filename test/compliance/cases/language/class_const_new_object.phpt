--TEST--
Language: class constants with object expressions — PHP 8.3+ (#3196, #9850, Zend/zend_constants.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsClassConstObjectExpressions()) {
    die('skip class const object expressions require CompilerVersion 8.3+');
}
?>
--FILE--
<?php
class C {
    public const X = new stdClass();
}
var_export(C::X);
echo "\n";
echo C::X === C::X ? "1\n" : "0\n";

class Foo {
    public function __construct(public int $x = 0) {}
}

class D {
    public const Y = new Foo(7);
}

var_export(D::Y);
echo "\n";
echo D::Y->x === 7 ? "1\n" : "0\n";
--EXPECT--
(object) array (
)
1
Foo::__set_state(array (
   'x' => 7,
))
1
