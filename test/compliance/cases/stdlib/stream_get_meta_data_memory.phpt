--TEST--
stream_get_meta_data() on php://memory — unread_bytes and seekable (issue #9601, php-src streams.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
fwrite($h, "hello\nworld\n");
fread($h, 3);
$meta = stream_get_meta_data($h);
echo is_array($meta) ? '1' : '0', "\n";
echo isset($meta['unread_bytes']) && $meta['unread_bytes'] === 0 ? '1' : '0', "\n";
echo isset($meta['seekable']) && ($meta['seekable'] === true || $meta['seekable'] === 1) ? '1' : '0', "\n";
echo isset($meta['wrapper_type']) && $meta['wrapper_type'] === 'PHP' ? '1' : '0', "\n";
echo isset($meta['stream_type']) && $meta['stream_type'] === 'MEMORY' ? '1' : '0', "\n";
fclose($h);
--EXPECT--
1
1
1
1
1
