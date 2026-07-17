--TEST--
SPL object storage clone deep-copies attached objects (#19805, ext/spl/spl_observer.c)
--FILE--
<?php
$s = new SplObjectStorage();
$o = new stdClass;
$s[$o] = 1;
$t = clone $s;
echo $t->count(), "\n";
echo $t->offsetExists($o) ? "y" : "n", "\n";
$p = new stdClass;
$t[$p] = 2;
echo $s->count(), ":", $t->count(), "\n";
echo $s->offsetExists($p) ? "y" : "n", "\n";
?>
--EXPECT--
1
y
1:2
n
