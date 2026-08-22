<?php
/**
 * #33721 — AOT array_reduce string callbacks (user fn + stdlib builtin).
 */
function sum($carry, $item)
{
    return $carry + $item;
}
echo 'user:', array_reduce([1, 2, 3], 'sum', 0), PHP_EOL;
echo 'user_noinit:', array_reduce([1, 2, 3], 'sum'), PHP_EOL;
echo 'intval:', array_reduce([1, 2, 3], 'intval', 0), PHP_EOL;
echo 'empty:', array_reduce([], 'sum', 7), PHP_EOL;
