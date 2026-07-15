--TEST--
Language: static property bare `new UserClass` on PHP 8.4 forward profile (#19046, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsStaticPropertyDefaultObjectExpressions()) {
    die('skip static property default new requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
class Node {
    public function __construct(public string $label = 'nil') {}
}

class ListHead {
    public static Node $nil = new Node;
}

echo ListHead::$nil->label, "\n";
echo ListHead::$nil === ListHead::$nil ? "1\n" : "0\n";
--EXPECT--
nil
1
