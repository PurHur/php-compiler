--TEST--
AOT: stream_get_meta_data() on php://memory returns metadata array (#9142, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);
$f = fopen('php://memory', 'r+');
$meta = stream_get_meta_data($f);
echo is_array($meta) ? '1' : '0', "\n";
echo ($meta['wrapper_type'] ?? '') === 'PHP' ? '1' : '0', "\n";
echo ($meta['seekable'] ?? false) ? '1' : '0', "\n";
fclose($f);
--EXPECT--
1
1
1
