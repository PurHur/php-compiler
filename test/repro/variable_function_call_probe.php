<?php

declare(strict_types=1);

function add(int $a, int $b): int
{
    return $a + $b;
}

$fn = 'add';
var_export($fn(2, 3));
echo "\n";

$name = $_GET['op'] ?? 'add';
var_export($name(4, 5));
echo "\n";
