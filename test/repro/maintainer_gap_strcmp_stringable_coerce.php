<?php

// Issue #14661 — strcmp family coerces Stringable via __toString (ext/standard/string.c).

class C implements Stringable
{
    public function __toString(): string
    {
        return 'abc';
    }
}

if (0 !== strcmp(new C(), 'abc')) {
    fwrite(STDERR, "strcmp equal Stringable failed\n");
    exit(1);
}
if (0 === strcmp(new C(), 'xyz')) {
    fwrite(STDERR, "strcmp unequal Stringable failed\n");
    exit(1);
}
if (0 !== strncmp(new C(), 'abc', 3)) {
    fwrite(STDERR, "strncmp equal Stringable failed\n");
    exit(1);
}
if (0 !== strcasecmp(new C(), 'ABC')) {
    fwrite(STDERR, "strcasecmp equal Stringable failed\n");
    exit(1);
}
if (0 !== strncasecmp(new C(), 'abc', 3)) {
    fwrite(STDERR, "strncasecmp equal Stringable failed\n");
    exit(1);
}

try {
    strcmp([], 'x');
    fwrite(STDERR, "strcmp array: uncaught\n");
    exit(1);
} catch (TypeError) {
}

echo "ok\n";
