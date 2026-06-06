<?php
$plain = 'hello';
$c = gzcompress($plain);
echo gzuncompress($c) === $plain ? "compress-round\n" : "compress-fail\n";
$raw = gzdeflate($plain);
echo gzinflate($raw) === $plain ? "deflate-round\n" : "deflate-fail\n";
$gzip = gzencode($plain);
echo substr($gzip, 0, 2) === "\x1f\x8b" ? "gzip-magic\n" : "gzip-fail\n";
echo gzdecode($gzip) === $plain ? "decode-round\n" : "decode-fail\n";
