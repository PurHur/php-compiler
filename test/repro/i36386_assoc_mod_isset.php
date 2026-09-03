<?php

declare(strict_types=1);

// AOT used to hang: `$i % $n` after a string-key for-loop boxed `$n` while loop
// `$i` stayed native i64, so `$i < $n` spun forever (#36386 / assoc-heavy).

$n = 10;
$map = [];
for ($i = 0; $i < $n; ++$i) {
    $map['k' . $i] = $i;
}

$hits = 0;
for ($i = 0; $i < $n; ++$i) {
    $key = 'k' . ($i % $n);
    if (isset($map[$key])) {
        ++$hits;
    }
}

echo $hits, '|', count($map), "\n";
