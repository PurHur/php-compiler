--TEST--
stream_bucket_new() returns stdClass bucket object (VM, #7086, #10325)
--FILE--
<?php
echo (int) class_exists('StreamBucket', false);
echo "\n";
echo (int) function_exists('stream_bucket_new');
echo "\n";
echo (int) function_exists('stream_bucket_make_writeable');
echo "\n";
$f = fopen('php://memory', 'r+');
$b = stream_bucket_new($f, 'hello');
echo $b->data, "\n";
echo (int) is_resource($b->bucket), "\n";
echo get_class($b), "\n";
--EXPECT--
0
1
1
hello
1
stdClass
