<?php

declare(strict_types=1);

if (!function_exists('get_object_id')) {
    echo "fail: get_object_id not registered\n";
    exit(1);
}

if (!function_exists('get_object_id') || !is_callable('get_object_id')) {
    echo "fail: introspection false while callable\n";
    exit(1);
}

class A {}
$o = new A();
echo get_object_id($o) > 0 ? "ok\n" : "fail\n";
