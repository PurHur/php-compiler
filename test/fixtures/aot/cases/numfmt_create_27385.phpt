--TEST--
numfmt_create() AOT instanceof NumberFormatter (#27385 / re-#20754)
--SKIPIF--
<?php if (!extension_loaded('intl')) die('skip intl required'); ?>
--FILE--
<?php
var_export(numfmt_create('en_US', NumberFormatter::DECIMAL) instanceof NumberFormatter);
echo "\n";
--EXPECT--
true
