<?php

declare(strict_types=1);

/**
 * #35980 leftover of #34673 / peer #35898 — two by-ref fetches of `$o->p[$k]` must share storage.
 * php-src: Zend/zend_execute.c ZEND_FETCH_DIM_W; Zend/zend_object_handlers.c zend_std_get_property_ptr_ptr
 */
class C
{
    public $p = ['a' => 1];
}
$o = new C();
$r =& $o->p['a'];
$s =& $o->p['a'];
$r = 5;
echo 'r=', $r, ' s=', $s, ' p=', $o->p['a'], "\n";
$s = 7;
echo 'r=', $r, ' s=', $s, ' p=', $o->p['a'], "\n";
