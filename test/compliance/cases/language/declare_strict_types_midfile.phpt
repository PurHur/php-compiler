--TEST--
Language: mid-file declare(strict_types=1) is compile-time fatal (#32182, Zend/zend_compile.c)
--FILE--
<?php
echo 'a';
declare(strict_types=1);
echo 'b';
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  strict_types declaration must be the very first statement in the script in %s on line %d
