<?php

declare(strict_types=1);

$a = [[1]];
foreach ($a as list(&$v)) {
    $v = 2;
}
if (2 !== $a[0][0]) {
    fwrite(STDERR, "list_ref_foreach: expected 2 got {$a[0][0]}\n");
    exit(1);
}

echo "ok\n";
