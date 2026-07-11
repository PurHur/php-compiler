--TEST--
Language: user implements Generator — compile-time fatal (#15445, Zend/zend_inheritance.c)
--FILE--
<?php
class G implements Generator {}
echo "reach\n";
--EXPECT_EXIT--
255
