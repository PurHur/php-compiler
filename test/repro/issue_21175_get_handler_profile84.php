<?php
/**
 * #21175 — get_error_handler()/get_exception_handler() must not exist under PROFILE=8.4.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21175_get_handler_profile84.php
 */
$err = function_exists('get_error_handler');
$ex = function_exists('get_exception_handler');
if ($err || $ex) {
    fwrite(STDERR, "fail: function_exists get_error_handler=" . var_export($err, true)
        . " get_exception_handler=" . var_export($ex, true) . " (want both false on 8.4)\n");
    exit(1);
}
echo "ok\n";
