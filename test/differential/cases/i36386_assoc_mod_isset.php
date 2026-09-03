<?php

declare(strict_types=1);

// Pure `$i % $n` after a string-key for-loop must not box `$n` while `$i` stays
// native — that mixed `$i < $n` compare spun forever under AOT (#36386).

$n = 8;
$map = [];
for ($i = 0; $i < $n; ++$i) {
    $map['k' . $i] = $i * 2;
}

$hits = 0;
for ($i = 0; $i < $n; ++$i) {
    $key = 'k' . ($i % $n);
    if (isset($map[$key])) {
        ++$hits;
    }
}

echo $hits, '|', count($map), "\n";
