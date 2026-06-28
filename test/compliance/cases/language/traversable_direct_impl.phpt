--TEST--
Language: direct implements Traversable — compile-time fatal (#13326, Zend/zend_interfaces.c)
--FILE--
<?php
class DirectTraversable implements Traversable {}
echo "reach\n";
--EXPECT_EXIT--
255
