--TEST--
AOT: typed string builtins null operand TypeError (#18598–#18601, ext/standard/string.c)
--FILE--
<?php
trim(null);
--EXPECT--
--EXPECT_EXIT--
134
