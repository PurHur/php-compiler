--TEST--
Generator throw on resume — uncaught trace labels generator as internal function (#14992)
--FILE--
<?php
function g(): Generator {
    yield 1;
    throw new Exception('x');
}
$g = g();
$g->next();
$g->next();
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Uncaught Exception: x in -:%d
Stack trace:
#0 [internal function]: g()
#1 -(%d): Generator->next()
#2 {main}
  thrown in - on line %d
