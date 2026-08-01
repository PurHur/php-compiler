--TEST--
Language: non-class types in intersection — compile fatal (#26401, zend_compile.c)
--FILE--
<?php
function f(): int&string {
    return "x";
}
echo f(), "\n";
--EXPECT_EXIT--
255
--EXPECTF--
%AType int cannot be part of an intersection type%A
