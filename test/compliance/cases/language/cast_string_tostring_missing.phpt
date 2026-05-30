--TEST--
Language: (string) object without __toString is fatal (Zend zend_operators.c, #3421)
--FILE--
<?php
class C {}
echo (string) (new C);
--EXPECT_EXIT--
255
