--TEST--
stdlib class_alias() alias-of-alias registers canonical class (#11639, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

class Chain11639 {}
var_export(class_alias('Chain11639', 'ChainB11639'));
echo "\n";
var_export(class_alias('ChainB11639', 'ChainC11639'));
echo "\n";
var_export(class_exists('ChainC11639', false));
echo "\n";
echo get_class(new ChainC11639()), "\n";
--EXPECT--
true
true
true
Chain11639
