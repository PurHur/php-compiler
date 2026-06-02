--TEST--
stdlib gzcompress/gzuncompress and gzencode/gzdecode round-trip (#3194)
--FILE--
<?php
echo function_exists('gzcompress') ? '1' : '0';
echo function_exists('gzuncompress') ? '1' : '0';
echo function_exists('gzdeflate') ? '1' : '0';
echo function_exists('gzinflate') ? '1' : '0';
echo function_exists('gzencode') ? '1' : '0';
echo function_exists('gzdecode') ? '1' : '0';
echo "\n";
$plain = 'hello';
$c = gzcompress($plain);
echo gzuncompress($c) === $plain ? "compress-round\n" : "compress-fail\n";
$raw = gzdeflate($plain);
echo gzinflate($raw) === $plain ? "deflate-round\n" : "deflate-fail\n";
$gzip = gzencode($plain);
echo substr($gzip, 0, 2) === "\x1f\x8b" ? "gzip-magic\n" : "gzip-fail\n";
echo gzdecode($gzip) === $plain ? "decode-round\n" : "decode-fail\n";
--EXPECT--
111111
compress-round
deflate-round
gzip-magic
decode-round
