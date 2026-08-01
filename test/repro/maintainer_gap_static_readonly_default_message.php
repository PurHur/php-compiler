<?php
// Issue #26487 — Zend prefers "cannot have default value" over static-readonly ban.
class A {
    public static readonly int $x = 1;
}
echo "compiled\n";
