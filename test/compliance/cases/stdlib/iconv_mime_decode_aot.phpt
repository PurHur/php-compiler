--TEST--
stdlib iconv_mime_decode() JIT/AOT RFC 2047 B-encoding (#27424)
--FILE--
<?php
echo iconv_mime_decode("=?UTF-8?B?SGVsbG8=?=", 0, "UTF-8"), "\n";
?>
--EXPECT--
Hello
