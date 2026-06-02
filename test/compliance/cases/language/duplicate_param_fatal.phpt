--TEST--
Language: duplicate parameter names in signature — compile-time fatal (#4282)
--FILE--
<?php
function dup(int $a, int $a): int {
    return $a;
}
echo dup(1, 2);
--EXPECT_EXIT--
255
