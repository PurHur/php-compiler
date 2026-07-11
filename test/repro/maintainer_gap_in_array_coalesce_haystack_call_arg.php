<?php

declare(strict_types=1);

$rows = ['items' => [1 => 2, 3 => 4]];

var_dump(in_array(2, $rows['items'] ?? [], true));
var_dump(array_search(2, $rows['items'] ?? [], true));

$h = $rows['items'] ?? [];
var_dump(in_array(2, $h, true));
