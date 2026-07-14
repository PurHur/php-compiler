--TEST--
Language: static property bare `new` on PHP 8.4 forward profile (#18816, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsPropertyDefaultObjectExpressions()) {
    die('skip static property bare new requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
class Node {
    public function __construct(public string $label = 'nil') {}
}
class Tree {
    public static Node $nil = new Node;
}
echo get_class(Tree::$nil), "\n";
echo Tree::$nil->label === 'nil' ? "1\n" : "0\n";
echo Tree::$nil === Tree::$nil ? "1\n" : "0\n";
?>
--EXPECT--
Node
1
1
