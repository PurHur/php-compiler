<?php

declare(strict_types=1);

$r = array_column([['x' => 1, 'y' => 2]], null, 'x');
var_export($r);
echo "\n";
if ($r !== [1 => ['x' => 1, 'y' => 2]]) {
    exit(1);
}
