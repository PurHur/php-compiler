--TEST--
stdlib EnumCases attribute class — not registered on PHP 8.2 reference profile (#17793, Zend/zend_attributes.c)
--FILE--
<?php
echo class_exists('EnumCases', false) ? "fail\n" : "ok\n";
--EXPECT--
ok
