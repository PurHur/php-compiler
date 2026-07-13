<?php
// Maintainer repro for #18580 — data:// stream_get_meta_data wrapper_type.

$fp = fopen('data://text/plain,hello', 'r');
$meta = stream_get_meta_data($fp);
echo 'wrapper=', $meta['wrapper_type'] ?? '?', "\n";
echo 'stream=', $meta['stream_type'] ?? '?', "\n";
fclose($fp);

$mem = fopen('php://memory', 'r+');
$memMeta = stream_get_meta_data($mem);
echo 'php_wrapper=', $memMeta['wrapper_type'] ?? '?', "\n";
fclose($mem);
