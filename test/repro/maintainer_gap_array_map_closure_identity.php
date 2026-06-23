<?php

declare(strict_types=1);

$closure = array_map(function (int $x): int {
    return $x * 2;
}, [1, 2, 3]);
$arrow = array_map(fn (int $x): int => $x * 2, [1, 2, 3]);
echo 'closure=', var_export($closure, true), "\n";
echo 'arrow=', var_export($arrow, true), "\n";
if ($closure !== [2, 4, 6] || $arrow !== [2, 4, 6]) {
    exit(1);
}
