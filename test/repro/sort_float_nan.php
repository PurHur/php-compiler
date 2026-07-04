<?php

declare(strict_types=1);

$b = [3.0, NAN, 1.0];
sort($b, SORT_REGULAR);
var_export($b);
echo "\n";

$c = [3.0, NAN, 1.0];
rsort($c, SORT_REGULAR);
var_export($c);
echo "\n";
