--TEST--
Stdlib: filter_id() — sanitizer name ids (#11419, ext/filter/filter.c)
--FILE--
<?php
echo filter_id('string'), "\n";
echo filter_id('number_int'), "\n";
echo filter_id('int'), "\n";
var_export(filter_id('validate_int'));
echo "\n";
--EXPECT--
513
519
257
false
