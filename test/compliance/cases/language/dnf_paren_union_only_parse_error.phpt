--TEST--
Language: parenthesized union-only DNF types — parse error (#9968, Zend/zend_compile.c)
--FILE--
<?php
function acceptsUnion((string|int) $x): void {}
echo "run\n";
--EXPECT_EXIT--
255
