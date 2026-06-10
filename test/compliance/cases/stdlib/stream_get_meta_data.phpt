--TEST--
stream_get_meta_data() on tmpfile() handle (issue #6007, php-src streams.c)
--FILE--
<?php
echo function_exists('stream_get_meta_data') ? '1' : '0', "\n";
$f = tmpfile();
$meta = stream_get_meta_data($f);
echo is_array($meta) ? '1' : '0', "\n";
echo isset($meta['wrapper_type']) && isset($meta['stream_type']) && isset($meta['mode']) && isset($meta['seekable']) ? '1' : '0', "\n";
echo ($meta['wrapper_type'] === 'plainfile' || $meta['wrapper_type'] === 'PHP') ? '1' : '0', "\n";
echo ($meta['seekable'] === true || $meta['seekable'] === 1) ? '1' : '0', "\n";
fclose($f);
--EXPECT--
1
1
1
1
1
