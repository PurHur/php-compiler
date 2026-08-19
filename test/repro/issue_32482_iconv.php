<?php
// Repro #32482 — leftover Type.php always-on __compiler_iconv dropped.
// iconv() AOT must still compile (php-src ext/iconv/iconv.c).
$utf8 = iconv('ISO-8859-1', 'UTF-8', "\xE9");
echo $utf8 === "\xC3\xA9" ? "iconv_ok\n" : "iconv_bad\n";
echo iconv('UTF-8', 'UTF-8', 'hello') === 'hello' ? "roundtrip_ok\n" : "roundtrip_bad\n";
