<?php
/** AOT probe for #24641 — named mt_rand must compile (not Unknown named parameter). */
try {
    $n = mt_rand(min: 1, max: 2);
    echo 'named_compiled=', is_int($n) ? 'int' : gettype($n), "\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter')
        ? "named_rejected\n"
        : ('named_other=' . get_class($e) . "\n");
}
echo 'pos=', is_int(mt_rand(1, 2)) ? 'int' : 'other', "\n";
echo 'zero=', is_int(mt_rand()) ? 'int' : 'other', "\n";
