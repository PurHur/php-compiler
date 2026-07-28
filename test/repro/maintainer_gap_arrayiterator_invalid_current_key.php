<?php
$it = new ArrayIterator([1]);
$it->next();
echo 'valid=', var_export($it->valid(), true), ' current=', var_export($it->current(), true), ' key=', var_export($it->key(), true), "\n";
$ao = new ArrayObject([1]);
$it2 = $ao->getIterator();
$it2->next();
echo 'ao valid=', var_export($it2->valid(), true), ' current=', var_export($it2->current(), true), ' key=', var_export($it2->key(), true), "\n";
