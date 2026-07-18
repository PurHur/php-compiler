<?php
// #20672 — password_get_info(null) TypeError on 8.4 forward profile
// (php-src ext/standard/password.c Z_PARAM_STR(hash))
// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20672_password_get_info_null_forward84.php
// AOT: PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/pwgi_null84 test/repro/issue_20672_password_get_info_null_forward84.php && /tmp/pwgi_null84
try {
    password_get_info(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
