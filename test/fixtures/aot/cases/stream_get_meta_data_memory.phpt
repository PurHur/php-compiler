--TEST--
AOT: stream_get_meta_data() on php://memory|temp matches Zend keys (#9142, #27659, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);
$f = fopen('php://memory', 'r+');
$meta = stream_get_meta_data($f);
echo is_array($meta) ? '1' : '0', "\n";
echo ($meta['wrapper_type'] ?? '') === 'PHP' ? '1' : '0', "\n";
echo ($meta['stream_type'] ?? '') === 'MEMORY' ? '1' : '0', "\n";
echo isset($meta['mode']) ? '1' : '0', "\n";
echo ($meta['seekable'] ?? false) ? '1' : '0', "\n";
fclose($f);
$t = fopen('php://temp', 'r+');
$tm = stream_get_meta_data($t);
echo ($tm['stream_type'] ?? '') === 'TEMP' ? '1' : '0', "\n";
fclose($t);
--EXPECT--
1
1
1
1
1
1
