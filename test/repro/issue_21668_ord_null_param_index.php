<?php
/**
 * Repro #21668 — ord(null) deprecation must cite parameter #1 ($character)
 * (Zend; was #2 via off-by-one $userArgIndex in ext/standard/ord.php).
 *
 * Use a variable so compile-time host fold cannot mask the VM emit path.
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21668_ord_null_param_index.php
 * JIT: PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_21668_ord_null_param_index.php
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo $msg, "\n";

        return true;
    }

    return false;
});
$character = null;
echo var_export(ord($character), true), "\n";
