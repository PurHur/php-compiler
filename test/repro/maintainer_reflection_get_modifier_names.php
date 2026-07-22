<?php
declare(strict_types=1);

// Issue #22127 — Reflection::getModifierNames() (ext/reflection/php_reflection.c)
if (!class_exists('Reflection')) {
    fwrite(STDERR, "FAIL: class Reflection missing\n");
    exit(1);
}

$cases = [
    17 => ['public', 'static'],
    1 => ['public'],
    2 => ['protected'],
    4 => ['private'],
    16 => ['static'],
    32 => ['final'],
    64 => ['abstract'],
    65 => ['abstract', 'public'],
    49 => ['final', 'public', 'static'],
    128 => ['readonly'],
    65536 => ['readonly'],
    7 => [], // PPP_MASK all three — no exact match
];

foreach ($cases as $mods => $want) {
    $got = Reflection::getModifierNames($mods);
    if ($got !== $want) {
        fwrite(STDERR, 'FAIL mods='.$mods.' got='.json_encode($got).' want='.json_encode($want)."\n");
        exit(1);
    }
    echo $mods, '=', json_encode($got), "\n";
}
echo "OK\n";
