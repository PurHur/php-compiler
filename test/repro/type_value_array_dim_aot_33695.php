<?php
/**
 * #33695 — TYPE_VALUE packed arrays must use HT dim, not ArrayAccess offsetGet.
 * php-src: Zend/zend_execute.c ZEND_FETCH_DIM_R; Zend/zend_hash.c zend_array_dup.
 */
class A
{
    public static $a = [1];
}

$b = A::$a;
echo $b[0], "\n";

$b[0] = 99;
echo A::$a[0], "\n";

$j = json_decode('[1]', true);
echo $j[0], "\n";

$ao = new ArrayObject([7]);
echo $ao[0], "\n";
