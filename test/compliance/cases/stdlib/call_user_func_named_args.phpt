--TEST--
stdlib call_user_func() forwards named arguments to user functions (issue #10637)
--FILE--
<?php
function sum_pair(int $a, int $b = 2): int
{
    return $a + $b;
}

echo call_user_func('sum_pair', b: 5, a: 1), "\n";
echo call_user_func_array('sum_pair', ['b' => 5, 'a' => 1]), "\n";
?>
--EXPECT--
6
6
