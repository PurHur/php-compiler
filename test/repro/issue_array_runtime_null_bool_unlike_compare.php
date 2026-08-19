<?php
/**
 * #32528 leftover of #32520 — array vs runtime/boxed null/bool ordered compare.
 * php-src: Zend/zend_operators.c compare_function / zend_hash_num_elements
 */
$n = null;
$f = false;
$t = true;
$e = [];
$a = [1];
echo ($e > $n) ? "en\n" : "nen\n";
echo ($e <=> $n), "\n";
echo ($e > $f) ? "ef\n" : "nef\n";
echo ($a > $t) ? "at\n" : "nat\n";
echo ($a <=> $t), "\n";
function n32528()
{
    return null;
}
echo ([] > n32528()) ? "fn\n" : "nfn\n";
echo ([] > null) ? "en2\n" : "nen2\n";
echo ([1] > 0) ? "nz\n" : "nnz\n";
