<?php

declare(strict_types=1);

$a = [3, 1, 2];
sort($a, Sorting::Ascending);
var_export($a);
echo "\n";

$b = [3, 1, 2];
sort($b, direction: SortDirection::Ascending);
var_export($b);
echo "\n";
