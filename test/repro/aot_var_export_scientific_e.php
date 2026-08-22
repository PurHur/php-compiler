<?php
// AOT thin var_export scientific exponent must be uppercase E (#33901 / ext/standard/var.c).
var_export(PHP_INT_MAX + 1);
echo "\n";
var_export(1.0e100);
echo "\n";
var_export(1.0E-10);
echo "\n";
