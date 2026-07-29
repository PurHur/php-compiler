<?php
/** Repro for #23586 — hash_final Reflection binary + named args. */
$r = new ReflectionFunction('hash_final');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
$c = hash_init('sha256');
hash_update($c, 'x');
try {
    echo hash_final(context: $c, binary: false), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$c2 = hash_init('sha256');
hash_update($c2, 'x');
echo hash_final($c2, false), "\n";
$c3 = hash_init('sha256');
hash_update($c3, 'x');
try {
    hash_final(context: $c3, raw_output: false);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
