<?php

declare(strict_types=1);

// Repro #22549 — Ds\Vector / Ds\Map / Ds\Set MVP.

if (!class_exists('Ds\\Vector')) {
    fwrite(STDERR, "fail: Ds\\Vector missing\n");
    exit(1);
}

$v = new Ds\Vector([1, 2, 3]);
if (3 !== $v->count()) {
    fwrite(STDERR, "fail: vector count\n");
    exit(1);
}

$m = new Ds\Map(['a' => 1]);
if (1 !== $m->get('a')) {
    fwrite(STDERR, "fail: map get\n");
    exit(1);
}

$s = new Ds\Set([1, 1, 2]);
if (2 !== $s->count() || !$s->contains(1)) {
    fwrite(STDERR, "fail: set\n");
    exit(1);
}

echo "ok\n";
