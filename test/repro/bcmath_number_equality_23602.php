<?php
/** Repro #23602 — BcMath\Number == uses compare handler (not property strings). */
use BcMath\Number;

$a = new Number('1.50');
$b = new Number('1.5');
var_export($a == $b);
echo "\n";
var_export($a <=> $b);
echo "\n";
var_export($a->compare($b));
echo "\n";
var_export((new Number('2')) == 2);
echo "\n";
var_export((new Number('2')) != 2);
echo "\n";
var_export((new Number('1.50')) === new Number('1.5'));
echo "\n";
