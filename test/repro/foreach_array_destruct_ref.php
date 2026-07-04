<?php

declare(strict_types=1);

$a = [[1, 2]];
foreach ($a as [$x, &$y]) {
    $y = 9;
}
if (9 !== $a[0][1]) {
    fwrite(STDERR, "foreach_array_destruct_ref: expected 9 got {$a[0][1]}\n");
    exit(1);
}

echo "ok\n";
