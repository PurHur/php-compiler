<?php
/**
 * user_error() excess argc → ArgumentCountError "at most 2" (#30690).
 * php-src: Zend/zend_builtin_functions.c (alias of trigger_error)
 */
try {
    user_error('x', E_USER_NOTICE, 1);
    echo "ue_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'ue_hi:ArgumentCountError:', $e->getMessage(), "\n";
}
try {
    user_error();
    echo "ue_lo:OK\n";
} catch (ArgumentCountError $e) {
    echo 'ue_lo:ArgumentCountError:', $e->getMessage(), "\n";
}
$er = error_reporting(0);
echo 'ue_ok:', user_error('x', E_USER_NOTICE) ? 'true' : 'false', "\n";
error_reporting($er);
try {
    trigger_error('x', E_USER_NOTICE, 1);
    echo "te_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'te_hi:ArgumentCountError:', $e->getMessage(), "\n";
}
