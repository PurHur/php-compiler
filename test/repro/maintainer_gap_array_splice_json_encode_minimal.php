<?php

declare(strict_types=1);

$a = ['x' => 1, 'y' => 2, 0 => 3];
array_splice($a, 1, 1, ['z' => 9]);
echo json_encode($a, JSON_THROW_ON_ERROR), "\n";
