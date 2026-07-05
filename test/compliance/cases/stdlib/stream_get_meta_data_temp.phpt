--TEST--
stream_get_meta_data() on php://temp — stream_type TEMP not MEMORY (#16577, ext/standard/streams.c)
--FILE--
<?php

declare(strict_types=1);

$h = fopen('php://temp', 'r+');
$meta = stream_get_meta_data($h);
echo isset($meta['stream_type']) && $meta['stream_type'] === 'TEMP' ? '1' : '0', "\n";
echo isset($meta['wrapper_type']) && $meta['wrapper_type'] === 'PHP' ? '1' : '0', "\n";
fclose($h);
--EXPECT--
1
1
