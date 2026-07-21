<?php
/**
 * Repro #21893 — iterator_to_array(null) TypeError (php-src-strict; ext/spl).
 *
 * VM:  php bin/vm.php test/repro/issue_21893_iterator_to_array_null_typeerror.php
 * JIT: php bin/jit.php test/repro/issue_21893_iterator_to_array_null_typeerror.php
 * AOT: php bin/compile.php -o /tmp/ita_null test/repro/issue_21893_iterator_to_array_null_typeerror.php && /tmp/ita_null
 */
try {
    var_export(iterator_to_array(null));
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
