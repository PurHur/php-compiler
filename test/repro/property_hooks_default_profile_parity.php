<?php
// Issue #24818 — property hooks must parse-error on default / PROFILE=8.2 (Zend 8.2).
class C {
    public string $x {
        get => 'g';
        set { echo "set\n"; }
    }
}
echo "parsed\n";
