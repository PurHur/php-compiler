--TEST--
ArrayIterator::current()/key() on invalid position — NULL (#24325, ext/spl/spl_array.c)
--FILE--
<?php
$it = new ArrayIterator([1]);
$it->next();
echo 'valid=', (int) $it->valid(), ' current=', var_export($it->current(), true), ' key=', var_export($it->key(), true), "\n";
$ao = new ArrayObject(['a' => 1]);
$it2 = $ao->getIterator();
$it2->next();
echo 'ao valid=', (int) $it2->valid(), ' current=', var_export($it2->current(), true), ' key=', var_export($it2->key(), true), "\n";
$ok = new ArrayIterator([10, 20]);
$ok->rewind();
echo 'ok=', $ok->current(), ',', $ok->key(), "\n";
?>
--EXPECT--
valid=0 current=NULL key=NULL
ao valid=0 current=NULL key=NULL
ok=10,0
