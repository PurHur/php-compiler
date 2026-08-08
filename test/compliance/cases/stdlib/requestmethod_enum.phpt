--TEST--
stdlib RequestMethod phantom absent (#28931, re-#7230)
--FILE--
<?php
var_export(enum_exists('RequestMethod', false));
echo "\n";
var_export(class_exists('RequestMethod', false));
echo "\n";
--EXPECT--
false
false
