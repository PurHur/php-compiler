<?php
/**
 * #24271 — LimitIterator current()/key() when invalid must be NULL.
 */
error_reporting(E_ALL);
$it = new LimitIterator(new ArrayIterator([1, 2, 3]), 1, 1);
$it->rewind();
$it->next();
$c1 = $it->current();
$k1 = $it->key();
$v1 = $it->valid();
$it->next();
$c2 = $it->current();
$k2 = $it->key();
$v2 = $it->valid();
echo 'c1=', var_export($c1, true), ' k1=', var_export($k1, true), ' valid=', (int) $v1, "\n";
echo 'c2=', var_export($c2, true), ' k2=', var_export($k2, true), ' valid=', (int) $v2, "\n";
$ok = (null === $c1 && null === $k1 && !$v1 && null === $c2 && null === $k2 && !$v2);
exit($ok ? 0 : 1);
