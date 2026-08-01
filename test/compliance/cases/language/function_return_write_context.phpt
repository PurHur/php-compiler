--TEST--
Language: function return in write context — compile-time fatal (#26436)
--FILE--
<?php
function &g(): int { static $x = 1; return $x; }
g() = 3;
echo "ASSIGNED\n";
--EXPECT_EXIT--
255
