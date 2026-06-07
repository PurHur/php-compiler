--TEST--
stdlib zend_thread_id() JIT — positive int thread id (#6870)
--FILE--
<?php
$id = zend_thread_id();
echo is_int($id) && $id > 0 ? "int\n" : "bad\n";
echo zend_thread_id() === $id ? "stable\n" : "bad\n";
--EXPECT--
int
stable
