--TEST--
LimitIterator current()/key() when invalid — NULL (#24271, ext/spl/spl_iterators.c)
--FILE--
<?php
$it = new LimitIterator(new ArrayIterator([1, 2, 3]), 1, 1);
$it->rewind();
echo 'in:', var_export($it->current(), true), ',', var_export($it->key(), true), ',', (int) $it->valid(), "\n";
$it->next();
echo 'past:', var_export($it->current(), true), ',', var_export($it->key(), true), ',', (int) $it->valid(), "\n";
$it->next();
echo 'past2:', var_export($it->current(), true), ',', var_export($it->key(), true), ',', (int) $it->valid(), "\n";
?>
--EXPECT--
in:2,1,1
past:NULL,NULL,0
past2:NULL,NULL,0
