--TEST--
Uncaught builtin TypeError fatal cites user call site, not ExceptionSupport (#6334)
--FILE--
<?php
class C {}
$o = new C();
array_key_exists('k', $o);
--EXPECT_EXIT--
255
