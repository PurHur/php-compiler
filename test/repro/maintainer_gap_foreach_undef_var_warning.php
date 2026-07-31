<?php
/**
 * Issue #26148 — foreach ($undefined as …) must emit Undefined variable E_WARNING
 * before the foreach type warning (Zend/zend_execute.c / zend_vm_def.h FE_RESET).
 *
 *   php test/repro/maintainer_gap_foreach_undef_var_warning.php
 *   php bin/vm.php test/repro/maintainer_gap_foreach_undef_var_warning.php
 *   php bin/jit.php test/repro/maintainer_gap_foreach_undef_var_warning.php
 */
error_reporting(E_ALL);
foreach ($undefined as $v) {
    echo $v;
}
echo "after\n";
