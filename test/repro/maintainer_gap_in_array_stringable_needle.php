<?php

// Issue #14663 — in_array() loose mode coerces Stringable needle (ext/standard/array.c).

class C implements Stringable
{
    public function __toString(): string
    {
        return 'x';
    }
}

if (!in_array(new C(), ['x'], false)) {
    fwrite(STDERR, "in_array loose Stringable needle failed\n");
    exit(1);
}
if (in_array(new C(), ['x'], true)) {
    fwrite(STDERR, "in_array strict Stringable needle should be false\n");
    exit(1);
}

echo "ok\n";
