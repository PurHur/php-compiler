<?php
declare(strict_types=1);

function double_it(mixed &$value, mixed $key): void
{
    $value = ((int)$value) * 2;
}

$arr = ['x' => 5, 'y' => 10];
array_walk($arr, 'double_it');
echo var_export($arr, true) . "\n";
