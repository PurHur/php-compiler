--TEST--
Stdlib: filter_id() — Zend ID parity for supported filters (#3485, ext/filter/filter.c)
--FILE--
<?php
echo filter_id('validate_email'), "\n";
echo filter_id('int'), "\n";
echo filter_id('boolean'), "\n";
echo filter_id('float'), "\n";
echo filter_id('validate_regexp'), "\n";
var_export(filter_id('not_a_filter'));
echo "\n";
--EXPECT--
274
257
258
259
272
false
