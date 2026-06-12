<?php
declare(strict_types=1);

// null callback zip (2 arrays)
var_export(array_map(null, [1, 2], ['a', 'b']));
echo "\n";

// closure over parallel arrays (3 args total)
var_export(array_map(fn ($a, $b) => $a + $b, [1, 2], [10, 20]));
echo "\n";

// three arrays zipped
var_export(array_map(null, [1, 2], ['a', 'b'], [true, false]));
echo "\n";
