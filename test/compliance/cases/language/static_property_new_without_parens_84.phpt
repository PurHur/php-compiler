--TEST--
Language: static property bare `new Class` rejected under PROFILE=8.4 (#21493, re-#18816, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Node {
    public function __construct(public string $tag = 'root') {}
}
class Tree {
    public static Node $root = new Node;
}
echo Tree::$root->tag, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: New expressions are not supported in this context
