--TEST--
ArrayObject empty-dim append + count (#27286, ext/spl/spl_array.c)
--FILE--
<?php
$o = new ArrayObject([1, 2, 3]);
$o[] = 4;
echo count($o), '|', $o[3], "\n";
$o->append(5);
echo count($o), '|', $o[4], "\n";
$o->offsetSet(null, 6);
echo count($o), '|', $o[5], "\n";
?>
--EXPECT--
4|4
5|5
6|6
