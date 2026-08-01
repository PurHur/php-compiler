--TEST--
Language: void/array/iterable/never/self in intersection — compile fatal (#26401)
--FILE--
<?php
function f(): void&string {}
echo "reached\n";
--EXPECT_EXIT--
255
--EXPECTF--
%AType void cannot be part of an intersection type%A
