<?php
// #29426 — get-only virtual + public private(set) must Fatal (Zend/zend_inheritance.c)
class C {
    public private(set) string $x {
        get => 'g';
    }
}
echo "parsed\n";
