<?php
/**
 * Repro for #15645 — Alias::class must resolve to alias name, not canonical.
 */
declare(strict_types=1);

class Real15655 {}
class_alias(Real15655::class, 'Alias15655');

$real = Real15655::class;
$alias = Alias15655::class;

if ('Real15655' !== $real) {
    fwrite(STDERR, "Real15655::class expected Real15655, got {$real}\n");
    exit(1);
}
if ('Alias15655' !== $alias) {
    fwrite(STDERR, "Alias15655::class expected Alias15655, got {$alias}\n");
    exit(1);
}

echo "ok\n";
