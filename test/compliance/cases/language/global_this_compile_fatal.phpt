--TEST--
Language: global $this is compile-time fatal (#32180, Zend/zend_compile.c)
--FILE--
<?php
function foo() {
    global $this;
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use $this as global variable in %s on line %d
