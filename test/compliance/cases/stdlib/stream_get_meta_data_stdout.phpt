--TEST--
stream_get_meta_data() on STDOUT — seekable false and php-src key order (#17428, ext/standard/streams.c)
--FILE--
<?php
$meta = stream_get_meta_data(STDOUT);
$expectedKeys = [
    'timed_out',
    'blocked',
    'eof',
    'wrapper_type',
    'stream_type',
    'mode',
    'unread_bytes',
    'seekable',
    'uri',
];
echo ($meta['seekable'] === false) ? '1' : '0', "\n";
echo (array_keys($meta) === $expectedKeys) ? '1' : '0', "\n";
echo ($meta['uri'] === 'php://stdout') ? '1' : '0', "\n";
$h = fopen('php://memory', 'r+');
$mem = stream_get_meta_data($h);
echo ($mem['seekable'] === true) ? '1' : '0', "\n";
fclose($h);
--EXPECT--
1
1
1
1
