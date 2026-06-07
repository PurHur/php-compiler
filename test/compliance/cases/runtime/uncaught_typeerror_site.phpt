--TEST--
Uncaught builtin TypeError fatal stack cites user site, not ExceptionSupport (#7343)
--FILE--
<?php
class C {}
$o = new C();
array_key_exists('k', $o);
--EXPECT_EXIT--
255
