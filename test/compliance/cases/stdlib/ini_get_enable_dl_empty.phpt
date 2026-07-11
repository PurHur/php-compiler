--TEST--
stdlib ini_get('enable_dl') Off boolean returns empty string (#12133, Zend/zend_ini.c)
--FILE--
<?php
echo ini_get('enable_dl') === '' ? "edl-empty\n" : "edl-bad\n";
echo gettype(ini_get('enable_dl')) === 'string' ? "edl-string\n" : "edl-type-bad\n";
--EXPECT--
edl-empty
edl-string
