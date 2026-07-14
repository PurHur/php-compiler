--TEST--
Language: static property bare `new Class` on PHP 8.4 forward profile (#18816, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsStaticPropertyDefaultObjectExpressions()) {
    die('skip static property default new requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
class Node {
    public function __construct(public string $tag = 'root') {}
}
class Tree {
    public static Node $root = new Node;
}
echo Tree::$root->tag, "\n";
echo Tree::$root === Tree::$root ? "1\n" : "0\n";
--EXPECT--
root
1
