--TEST--
Language: never in intersection — Zend-shaped compile fatal (#26401)
--FILE--
<?php
function f(): never&Stringable {}
echo "reached\n";
--EXPECT_EXIT--
255
--EXPECTF--
%AType never cannot be part of an intersection type%A
