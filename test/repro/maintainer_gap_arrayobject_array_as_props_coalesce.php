<?php
/**
 * ARRAY_AS_PROPS ?? must read backing store like isset/read (php-src ext/spl/spl_array.c).
 * Expected Zend: coal=1 miss=fb ai=2
 */
$a = new ArrayObject(['x' => 1], ArrayObject::ARRAY_AS_PROPS);
echo 'read=', $a->x, "\n";
echo 'isset=', isset($a->x) ? '1' : '0', "\n";
echo 'coal=', ($a->x ?? 'no'), "\n";
echo 'miss=', ($a->missing ?? 'fb'), "\n";
$ai = new ArrayIterator(['y' => 2], ArrayIterator::ARRAY_AS_PROPS);
echo 'ai=', ($ai->y ?? 'no'), "\n";
