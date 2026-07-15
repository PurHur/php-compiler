<?php
// Issue #19172 — readonly class / readonly property with hooks must compile-error (PHP 8.4).

readonly class R {
    public int $x {
        get => 1;
        set => $value;
    }
}

class C {
    public readonly int $x {
        get => 1;
    }
}

echo "readonly prop hook bad ok\n";
