<?php
// php-src ext/standard/streams.c — stream_get_meta_data wrapper_type (#18580, #18581).
$data = fopen('data://text/plain,hello', 'r');
$meta = stream_get_meta_data($data);
echo 'data_wrapper=', $meta['wrapper_type'], "\n";
fclose($data);

$filterUri = 'php://filter/read=string.rot13/resource=data://text/plain,hello';
$filter = fopen($filterUri, 'r');
$filterMeta = stream_get_meta_data($filter);
echo 'filter_wrapper=', $filterMeta['wrapper_type'], "\n";
echo 'filter_uri=', $filterMeta['uri'], "\n";
fclose($filter);
