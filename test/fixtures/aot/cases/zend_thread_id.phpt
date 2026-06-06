--TEST--
AOT zend_thread_id() — positive int thread id (#6870)
--FILE--
<?php
$id = zend_thread_id();
echo is_int($id) && $id > 0 ? "int\n" : "bad\n";
--EXPECT--
int
