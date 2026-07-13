--TEST--
stream_get_meta_data() data:// — wrapper_type RFC2397 not plainfile (#18580)
--FILE--
<?php
$fp = fopen('data://text/plain,hello', 'r');
$meta = stream_get_meta_data($fp);
echo 'wrapper=', $meta['wrapper_type'] ?? '?', "\n";
echo 'stream=', $meta['stream_type'] ?? '?', "\n";
fclose($fp);

$mem = fopen('php://memory', 'r+');
$memMeta = stream_get_meta_data($mem);
echo 'php_wrapper=', $memMeta['wrapper_type'] ?? '?', "\n";
fclose($mem);
?>
--EXPECT--
wrapper=RFC2397
stream=RFC2397
php_wrapper=PHP
