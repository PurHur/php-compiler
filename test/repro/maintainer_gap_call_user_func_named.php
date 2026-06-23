<?php

function sum_pair(int $a, int $b = 2): int
{
    return $a + $b;
}

var_export(call_user_func('sum_pair', b: 5, a: 1));
echo "\n";
