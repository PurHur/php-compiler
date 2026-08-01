--TEST--
Language: class cannot extend trait (#26537, zend_inheritance.c)
--FILE--
<?php
trait T {}
class C extends T {}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class C cannot extend trait T
