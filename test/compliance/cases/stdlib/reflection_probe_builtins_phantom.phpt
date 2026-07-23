--TEST--
stdlib attribute_exists()/class_meth_exists()/unitenum_exists() — not advertised on PHP 8.2 reference profile (#14995, #17138, #22584)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo function_exists('attribute_exists') ? "ae_fail\n" : "ae_ok\n";
echo function_exists('class_meth_exists') ? "cme_fail\n" : "cme_ok\n";
echo function_exists('unitenum_exists') ? "ue_fail\n" : "ue_ok\n";
--EXPECT--
ae_ok
cme_ok
ue_ok
