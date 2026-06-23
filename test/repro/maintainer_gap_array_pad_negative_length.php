<?php

declare(strict_types=1);

$r1 = array_pad([0, 1], -4, 0);
$r2 = array_pad([1, 2, 3], -5, 0);
$r3 = array_pad([], -3, 'x');
echo var_export($r1, true), "\n";
echo var_export($r2, true), "\n";
echo var_export($r3, true), "\n";
