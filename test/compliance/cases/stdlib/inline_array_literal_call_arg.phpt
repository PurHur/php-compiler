--TEST--
stdlib inline array literal call arguments (php-cfg dead arg temp vs producer slot)
--FILE--
<?php
echo count([1, 2, 3]), "\n";
function add(int $a, int $b): int { return $a + $b; }
echo call_user_func_array('add', [4, 5]), "\n";
?>
--EXPECT--
3
9
