--TEST--
Language: JIT echo array literal — prints Array (#4964, zend_compile.c)
--FILE--
<?php
echo [1, 2];
--EXPECT--
Array
