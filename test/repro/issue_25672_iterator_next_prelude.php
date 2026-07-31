<?php
// Iterator pointer stmt must still run; only current() feeds var_export (#13901 / #25672 guard).
$it = new ArrayIterator([10, 20, 30]);
$it->next();
echo var_export($it->current(), true), "\n";
$it->next();
echo var_export($it->current(), true), "\n";
