--TEST--
AOT iconv_mime_decode() RFC 2047 B-encoding (#27424)
--FILE--
<?php
echo iconv_mime_decode("=?UTF-8?B?SGVsbG8=?=", 0, "UTF-8"), "\n";
$enc = "=?UTF-8?B?V29ybGQ=?=";
echo iconv_mime_decode($enc, 0, "UTF-8"), "\n";
?>
--EXPECT--
Hello
World
