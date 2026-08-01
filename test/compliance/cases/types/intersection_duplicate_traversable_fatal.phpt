--TEST--
Language: Traversable&Countable&Traversable — compile fatal Duplicate type Traversable (#26605)
--FILE--
<?php
function f(Traversable&Countable&Traversable $x) {}
echo "reached\n";
--EXPECT_EXIT--
255
--EXPECTF--
%ADuplicate type Traversable is redundant%A
