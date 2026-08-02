--TEST--
stream_bucket_new() returns stdClass bucket object JIT (#6323, #10325)
--JIT--
--SKIPIF--
<?php
$profile = getenv('PHP_COMPILER_PROFILE');
if (\is_string($profile) && '' !== trim($profile)
    && version_compare(ltrim(trim($profile), 'vV'), '8.4', '>=')) {
    die('skip StreamBucket class on PROFILE≥8.4 (#26923)');
}
--FILE--
<?php
echo (int) class_exists('StreamBucket', false);
echo "\n";
echo (int) function_exists('stream_bucket_new');
echo "\n";
$f = fopen('php://memory', 'r+');
$b = stream_bucket_new($f, 'hello');
echo $b->data, "\n";
echo (int) is_resource($b->bucket), "\n";
echo get_class($b), "\n";
--EXPECT--
0
1
hello
1
stdClass
