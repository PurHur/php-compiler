<?php

declare(strict_types=1);

// Repro for #36252: top-level array append should be O(n), not O(n²).
$n = (int) ($argv[1] ?? 8000);
$a = [];
for ($i = 0; $i < $n; $i++) {
    $a[] = $i;
}
echo count($a), "\n";
