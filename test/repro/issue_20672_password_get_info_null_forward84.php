<?php
// #21537 (reverts #20672 inverted TypeError) — password_get_info(null) soft-null on 8.4
// Zend: E_DEPRECATED + unknown-algo array (php-src ext/standard/password.c Z_PARAM_STR soft-null)
// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20672_password_get_info_null_forward84.php
// AOT: PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/pwgi_null84 test/repro/issue_20672_password_get_info_null_forward84.php && /tmp/pwgi_null84
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if (E_DEPRECATED === $no) {
        echo 'DEP:', $str, "\n";
        return true;
    }
    return false;
});
try {
    $info = password_get_info(null);
    echo $info['algoName'], "\n";
    echo null === $info['algo'] ? "algo_null\n" : "algo_set\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
