<?php

declare(strict_types=1);

// #36252 — top-level packed append must stay linear (regression: rc=2 COW per store).
$n = (int) ($argv[1] ?? 16000);
$a = [];
for ($i = 0; $i < $n; $i++) {
    $a[] = $i;
}
echo count($a), "\n";
