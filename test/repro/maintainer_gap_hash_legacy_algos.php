<?php

declare(strict_types=1);

/**
 * Repro #13629 — hash_algos() registry entries must digest via hash() (ext/hash/hash.c).
 */

$data = 'test';

$cases = [
    ['ripemd160', '5e52fee47e6b070565f74372468cdc699de89107'],
    ['snefru', '8d25dd0b5715f7e4c799ade3a34b5f6148d0ce416992b5c2eaf614d35d5b3d30'],
    ['haval128,3', 'a26075021e24a5bda74794d85e9fdb7f'],
    ['joaat', '3f75ccc1'],
    ['tiger192,3', '7ab383fc29d81f8d0d68e87c69bae5f1f18266d730c48b1d'],
];

foreach ($cases as [$algo, $want]) {
    $digest = hash($algo, $data);
    if ($digest !== $want) {
        echo "fail:$algo:got:$digest:want:$want\n";
        exit(1);
    }
    echo "ok:$algo\n";
}

echo "done\n";
