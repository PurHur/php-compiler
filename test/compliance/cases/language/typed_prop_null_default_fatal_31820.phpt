--TEST--
Language: null default on non-nullable typed property — Zend suggests ?T (#31820, zend_compile.c)
--FILE--
<?php
class T {
    public int $x = null;
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Default value for property of type int may not be null. Use the nullable type ?int to allow null default value
