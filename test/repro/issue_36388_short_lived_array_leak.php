<?php

/**
 * Short-lived arrays in {main} must free under AOT (#36388 / re-#36409).
 *
 * TYPE_MASKED_ARRAY includes the TYPE_OBJECT bit, so {main}'s __destruct defer
 * previously skipped __hashtable__dtor + free. Double-addref on value-box HT
 * assign and INIT_ARRAY→CV assign also left rc=2 so unset never destroyed.
 *
 * php-src: Zend/zend_variables.c zval_ptr_dtor → rc_dtor_function (IS_ARRAY);
 * Zend/zend_execute.c zend_assign_to_variable.
 */
$n = (int) ($argv[1] ?? 20000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $a = ['x' => $i];
    unset($a);
}
$peak = memory_get_peak_usage(false);
$usage = memory_get_usage(false);
echo 'done n=', $n, ' peak=', $peak, ' usage=', $usage, ' delta=', ($usage - $u0), "\n";
