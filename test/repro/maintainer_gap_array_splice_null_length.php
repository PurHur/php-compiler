<?php

declare(strict_types=1);

$a = [1, 2, 3, 4];
$r = array_splice($a, 1, null);
echo var_export($a, true), "\n";
echo var_export($r, true), "\n";

$b = [1, 2, 3];
array_splice($b, 0, null);
echo var_export($b, true), "\n";
