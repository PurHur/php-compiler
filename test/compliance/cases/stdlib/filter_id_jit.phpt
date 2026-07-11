--TEST--
Stdlib: filter_id() JIT — Zend ID parity (#3485)
--FILE--
<?php
echo filter_id('validate_email'), "\n";
echo filter_id('int'), "\n";
var_export(filter_id('not_a_filter'));
echo "\n";
--EXPECT--
274
257
false
