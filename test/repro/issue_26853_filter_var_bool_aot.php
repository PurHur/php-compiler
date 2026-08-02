<?php
/**
 * #26853 — filter_var(FILTER_VALIDATE_BOOL) thin AOT must match Zend/VM (not module-verify fail).
 *
 * Run: php bin/vm.php test/repro/issue_26853_filter_var_bool_aot.php
 * AOT: php bin/compile.php -o /tmp/fb test/repro/issue_26853_filter_var_bool_aot.php && /tmp/fb
 */
$got = filter_var('yes', FILTER_VALIDATE_BOOL);
if (true !== $got) {
    echo 'FAIL yes ', var_export($got, true), "\n";
    exit(1);
}
$got = filter_var('no', FILTER_VALIDATE_BOOL);
if (false !== $got) {
    echo 'FAIL no ', var_export($got, true), "\n";
    exit(1);
}
$got = filter_var('maybe', FILTER_VALIDATE_BOOL);
if (false !== $got) {
    echo 'FAIL maybe ', var_export($got, true), "\n";
    exit(1);
}
$got = filter_var(true, FILTER_VALIDATE_BOOL);
if (true !== $got) {
    echo 'FAIL bool-true ', var_export($got, true), "\n";
    exit(1);
}
$got = filter_var(false, FILTER_VALIDATE_BOOL);
if (false !== $got) {
    echo 'FAIL bool-false ', var_export($got, true), "\n";
    exit(1);
}
echo "ok\n";
