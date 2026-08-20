<?php
// AOT thin var_export whole-float must keep ".0" (#32746 / ext/standard/var.c).
var_export(3.0);
echo "\n";
var_export(0.0);
echo "\n";
var_export(-0.0);
echo "\n";
var_export(10.0);
echo "\n";
var_export(1.5);
echo "\n";
