<?php

declare(strict_types=1);

$r = array_intersect_assoc(
    array_keys(['a' => 1, 'b' => 2]),
    array_keys(['a' => 9, 'c' => 3])
);
var_export($r);
echo "\n";
echo "ok\n";
