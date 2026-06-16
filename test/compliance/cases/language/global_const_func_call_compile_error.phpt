--TEST--
Language: function call in global const initializer must compile-error (#8809, Zend/zend_compile.c)
--FILE--
<?php
const C = array_find([1, 2, 3], fn($v) => $v > 1);
--EXPECT_EXIT--
255
