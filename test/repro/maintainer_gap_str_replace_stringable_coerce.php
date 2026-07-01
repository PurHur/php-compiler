<?php

// Issue #14662 — str_replace()/str_ireplace() coerce Stringable $subject (ext/standard/string.c).

class C implements Stringable
{
    public function __toString(): string
    {
        return 'abc';
    }
}

if ('bbc' !== str_replace('a', 'b', new C())) {
    fwrite(STDERR, "str_replace Stringable subject failed\n");
    exit(1);
}
if ('bbc' !== str_ireplace('A', 'b', new C())) {
    fwrite(STDERR, "str_ireplace Stringable subject failed\n");
    exit(1);
}

echo "ok\n";
