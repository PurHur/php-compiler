<?php
// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20253_error_log_null_forward84.php
// AOT: PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/error_log_null84 test/repro/issue_20253_error_log_null_forward84.php && /tmp/error_log_null84
try {
    var_export(error_log(null));
    echo " COERCED\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
