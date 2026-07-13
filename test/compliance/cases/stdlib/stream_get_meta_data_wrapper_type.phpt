--TEST--
stream_get_meta_data() wrapper_type for data:// and php://filter (#18580, #18581, ext/standard/streams.c)
--FILE--
<?php
$data = fopen('data://text/plain,hello', 'r');
$meta = stream_get_meta_data($data);
echo $meta['wrapper_type'], "\n";
fclose($data);

$filterUri = 'php://filter/read=string.rot13/resource=data://text/plain,hello';
$filter = fopen($filterUri, 'r');
$filterMeta = stream_get_meta_data($filter);
echo $filterMeta['wrapper_type'], "\n";
echo $filterMeta['uri'], "\n";
fclose($filter);
--EXPECT--
RFC2397
PHP
php://filter/read=string.rot13/resource=data://text/plain,hello
