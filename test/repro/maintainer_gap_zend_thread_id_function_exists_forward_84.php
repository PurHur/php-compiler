<?php

declare(strict_types=1);

if (!function_exists('zend_thread_id')) {
    echo "fail: function_exists(zend_thread_id) false on forward 8.4 profile\n";
    exit(1);
}

$id = zend_thread_id();
if (!\is_int($id) || $id <= 0) {
    echo "fail: zend_thread_id() returned invalid id\n";
    exit(1);
}

echo "ok\n";
