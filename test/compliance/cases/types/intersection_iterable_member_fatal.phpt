--TEST--
Language: iterable in intersection expands to Traversable|array fatal (#26401)
--FILE--
<?php
function f(): iterable&Countable {}
echo "reached\n";
--EXPECT_EXIT--
255
--EXPECTF--
%AType Traversable|array cannot be part of an intersection type%A
