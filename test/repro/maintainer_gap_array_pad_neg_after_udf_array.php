<?php

function hold(array $v): void
{
}

hold([]);
$r1 = array_pad([1, 2], -4, 0);
var_export($r1);
echo "\n";

$len = -4;
hold([]);
$r2 = array_pad([1, 2], $len, 0);
var_export($r2);
echo "\n";

hold([]);
$r3 = array_pad([1, 2], 4, 0);
var_export($r3);
echo "\n";

$r4 = array_pad([1, 2], -4, 0);
var_export($r4);
echo "\n";
