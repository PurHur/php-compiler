<?php
/**
 * Repro #22679 — preg_grep($pattern, null) → TypeError (php-src ext/pcre/php_pcre.c).
 *
 * VM:  php bin/vm.php test/repro/issue_22679_preg_grep_null_typeerror.php
 * JIT: php bin/jit.php test/repro/issue_22679_preg_grep_null_typeerror.php
 */
error_reporting(E_ALL);
try {
    var_export(preg_grep('/a/', null));
    echo " (uncaught)\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
