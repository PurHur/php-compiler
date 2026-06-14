--TEST--
StreamBucket class and stream_bucket_new() JIT (#6323, #7089)
--JIT--
--FILE--
<?php
echo (int) class_exists('StreamBucket', false);
echo "\n";
echo (int) function_exists('stream_bucket_new');
echo "\n";
$f = fopen('php://memory', 'r+');
$b = stream_bucket_new($f, 'hello');
echo get_class($b), "\n";
echo $b->data, "\n";
echo (int) is_resource($b->bucket), "\n";
--EXPECT--
1
1
StreamBucket
hello
1
