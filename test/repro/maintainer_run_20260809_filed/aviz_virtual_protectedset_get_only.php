<?php
// #29426 — get-only virtual + public protected(set) must Fatal (Zend/zend_inheritance.c)
class C {
    public protected(set) string $x {
        get => 'g';
    }
}
echo "parsed\n";
