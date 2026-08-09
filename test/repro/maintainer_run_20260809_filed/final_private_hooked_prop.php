<?php
// Repro for #29425 — Zend Fatal: Property cannot be both final and private
class C {
    final private string $x {
        get => 'g';
        set {}
    }
}
echo "parsed\n";
