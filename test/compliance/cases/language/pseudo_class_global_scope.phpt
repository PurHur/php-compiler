--TEST--
Language: parent::class outside class scope — Zend global scope fatal (issue #5024, zend_compile.c)
--FILE--
<?php
class C extends stdClass {}
echo parent::class;
--EXPECT_EXIT--
255
