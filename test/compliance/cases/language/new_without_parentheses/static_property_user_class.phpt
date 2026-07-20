--TEST--
Language: static property bare `new UserClass` rejected under PROFILE=8.4 (#21493, re-#19046, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Node {
    public function __construct(public string $label = 'nil') {}
}

class ListHead {
    public static Node $nil = new Node;
}

echo ListHead::$nil->label, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: New expressions are not supported in this context
