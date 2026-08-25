<?php
/**
 * #34748 / #27286 — ArrayObject/ArrayIterator `$o[]=` after construct-with-array
 * must append into `__spl_ht` (php-src ext/spl/spl_array.c).
 */
$o = new ArrayObject([1, 2, 3]);
$o[] = 4;
echo count($o), '|', $o[3], "\n";

$it = new ArrayIterator([1, 2, 3]);
$it[] = 4;
echo count($it), '|', $it[3], "\n";

$empty = new ArrayObject();
$empty[] = 'x';
echo count($empty), '|', $empty[0], "\n";

$o2 = new ArrayObject([1, 2, 3]);
$o2->append(5);
echo count($o2), '|', $o2[3], "\n";
