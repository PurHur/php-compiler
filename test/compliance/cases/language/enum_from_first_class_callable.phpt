--TEST--
Language: backed enum E::from(...)/tryFrom(...) first-class static callable (#7025, zend_enum.c)
--FILE--
<?php
enum E: int { case A = 1; }
$from = E::from(...);
echo $from(1)->name, "\n";
$tryFrom = E::tryFrom(...);
echo $tryFrom(1)->name, "\n";
var_export($tryFrom(99));
--EXPECT--
A
A
NULL