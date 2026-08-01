--TEST--
Language: parent in union type without parent — compile fatal (#26540, zend_compile.c)
--FILE--
<?php
class A {
    function f(): parent|int {
        return 1;
    }
}
echo "reached\n";
--EXPECT_EXIT--
255
--EXPECTF--
%ACannot use "parent" when current class scope has no parent%A
