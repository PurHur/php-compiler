--TEST--
stdlib zend_version() matches reference profile Zend 8.2 engine version (#12471, Zend/zend.c)
--FILE--
<?php
declare(strict_types=1);
echo zend_version(), "\n";
--EXPECT--
4.2.31
