--TEST--
stdlib zend_thread_id() — positive int thread id (Zend/zend.c, #6870)
--FILE--
<?php
if (!function_exists('zend_thread_id')) {
    echo "missing\n";
    exit;
}
$id = zend_thread_id();
echo is_int($id) && $id > 0 ? "int\n" : "bad\n";
echo zend_thread_id() === $id ? "stable\n" : "bad\n";
--EXPECT--
int
stable
