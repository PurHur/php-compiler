<?php
/**
 * #32426 — numeric-string ⊙ int overflow must promote to float
 * (leftover of #31964 / #32422; zend_operators.h ZEND_SIGNED_ADD_OVERFLOW).
 */
echo "s+i "; var_dump("9223372036854775807" + 1);
echo "i+s "; var_dump(1 + "9223372036854775807");
echo "s+s "; var_dump("9223372036854775807" + "1");
echo "s*i "; var_dump("9223372036854775807" * 2);
echo "i*s "; var_dump(2 * "9223372036854775807");
$s = "9223372036854775807";
$n = 1;
echo "rt+ "; var_dump($s + $n);
echo "ok+ "; var_dump("10" + 3);
echo "ok- "; var_dump("10" - 3);
echo "ok* "; var_dump("6" * "7");
