<?php
// Issue #30856 / #24819 — asymmetric visibility must fatal on default / Zend 8.2 reference profile.
class C {
    public private(set) string $name = 'Alice';
}
echo "parsed\n";
echo (new C())->name, "\n";
