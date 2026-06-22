--TEST--
Language: undefined bare constant in namespace cites FQCN once (#10510, zend_constants.c)
--FILE--
<?php
namespace N;
var_export(empty(UNDEF_CONST));
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Uncaught Error: Undefined constant "N\UNDEF_CONST"%A
