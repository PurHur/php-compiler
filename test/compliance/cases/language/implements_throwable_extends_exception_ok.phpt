--TEST--
Language: extends Exception implements Throwable allowed (#25869, Zend/zend_exceptions.c)
--FILE--
<?php
class Z extends Exception implements Throwable {}
echo class_exists('Z', false) ? "ok\n" : "missing\n";
--EXPECT--
ok
