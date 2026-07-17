--TEST--
AOT: implode(null) TypeError on 8.4 forward profile (#19894)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Literal null before an array literal boxes as TYPE_VALUE without isNullConstant
// on AOT; variable form exercises the same Z_PARAM_STR 8.4 TypeError path.
$sep = null;
implode($sep, ['a', 'b']);
--EXPECT--
--EXPECT_EXIT--
255
