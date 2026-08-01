--TEST--
Language: param int&string intersection — compile fatal (#26401)
--FILE--
<?php
function f(int&string $x) {}
echo "reached\n";
--EXPECT_EXIT--
255
--EXPECTF--
%AType int cannot be part of an intersection type%A
