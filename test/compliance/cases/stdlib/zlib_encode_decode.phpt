--TEST--
stdlib zlib_encode()/zlib_decode() RAW/DEFLATE/GZIP round-trip (#6288)
--FILE--
<?php
echo function_exists('zlib_encode') ? '1' : '0';
echo function_exists('zlib_decode') ? '1' : '0';
echo defined('ZLIB_ENCODING_RAW') ? '1' : '0';
echo defined('ZLIB_ENCODING_DEFLATE') ? '1' : '0';
echo defined('ZLIB_ENCODING_GZIP') ? '1' : '0';
echo "\n";
$raw = 'hello zlib';
foreach ([
    'RAW' => ZLIB_ENCODING_RAW,
    'DEFLATE' => ZLIB_ENCODING_DEFLATE,
    'GZIP' => ZLIB_ENCODING_GZIP,
] as $label => $encoding) {
    $enc = zlib_encode($raw, $encoding);
    $dec = zlib_decode($enc);
    echo $label, ':', ($dec === $raw ? 'ok' : 'fail'), "\n";
}
--EXPECT--
11111
RAW:ok
DEFLATE:ok
GZIP:ok
