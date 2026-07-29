<?php
// Issue #24819 — default / reference profile must reject (Zend 8.2).
// Forward accept: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_asymmetric_visibility.php
class C {
    public private(set) string $name = 'Alice';
}
echo "parsed\n";
echo (new C())->name, "\n";
