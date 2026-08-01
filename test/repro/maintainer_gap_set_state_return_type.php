<?php
// Issue #26484 — Zend requires __set_state return type to be object-ish when declared.
class C {
    public static function __set_state(array $a): int {
        return 1;
    }
}
echo "compiled\n";
