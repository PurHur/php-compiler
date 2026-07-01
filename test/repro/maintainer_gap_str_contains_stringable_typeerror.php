<?php

// Issue #14660 — str_contains()/str_starts_with()/str_ends_with() reject Stringable (ext/standard/string.c).

class C implements Stringable
{
    public function __toString(): string
    {
        return 'obj';
    }
}

foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $fn) {
    try {
        $fn(new C(), 'obj');
        fwrite(STDERR, "$fn: uncaught\n");
        exit(1);
    } catch (TypeError $e) {
        if (!str_contains($e->getMessage(), 'must be of type string')) {
            fwrite(STDERR, "$fn: wrong message: {$e->getMessage()}\n");
            exit(1);
        }
    }
}

echo "ok\n";
