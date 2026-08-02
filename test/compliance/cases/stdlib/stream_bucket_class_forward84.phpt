--TEST--
stream_bucket_new() returns final StreamBucket under PROFILE=8.4 (VM, #26923)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo (int) class_exists('StreamBucket', false);
echo "\n";
echo (int) (new ReflectionClass('StreamBucket'))->isFinal();
echo "\n";
$f = fopen('php://memory', 'r+');
$b = stream_bucket_new($f, 'hello');
echo get_class($b), "\n";
echo $b->data, "\n";
echo $b->datalen, "\n";
echo $b->dataLength, "\n";
$b->data = 'hi!';
echo $b->data, '|', $b->datalen, '|', $b->dataLength, "\n";
$b->dataLength = 99;
echo $b->dataLength, "\n";
$b->datalen = 7;
echo $b->datalen, '|', $b->dataLength, "\n";
--EXPECT--
1
1
StreamBucket
hello
5
5
hi!|5|5
99
7|99
