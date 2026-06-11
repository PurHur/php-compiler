--TEST--
stdlib zlib_encode()/zlib_decode() JIT round-trip (#6288)
--FILE--
<?php
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
RAW:ok
DEFLATE:ok
GZIP:ok
