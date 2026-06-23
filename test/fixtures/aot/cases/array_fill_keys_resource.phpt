--TEST--
AOT: array_fill_keys() — resource keys stringify to Resource id #N (#10847, ext/standard/array.c)
--FILE--
<?php
$stream = fopen('php://memory', 'r+');
$r = array_fill_keys([$stream], 1);
$key = 'Resource id #'.(string) get_resource_id($stream);
echo isset($r[$key]) ? "ok\n" : "fail\n";
--EXPECT--
ok
