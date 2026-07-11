--TEST--
stdlib ini_get() Off boolean directives return empty string (#11356, Zend/zend_ini.c)
--FILE--
<?php
echo ini_get('display_errors') === '' ? "de-empty\n" : "de-bad\n";
echo ini_get('short_open_tag') === '' ? "sot-empty\n" : "sot-bad\n";
echo ini_get('register_argc_argv') === '1' ? "raa-one\n" : "raa-bad\n";
echo ini_get('zend.enable_gc') === '1' ? "gc-one\n" : "gc-bad\n";
ini_set('display_errors', '0');
echo ini_get('display_errors') === '0' ? "set-off-zero\n" : "set-off-bad\n";
--EXPECT--
de-empty
sot-empty
raa-one
gc-one
set-off-zero
