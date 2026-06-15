--TEST--
stdlib mb_encode_mimeheader()/mb_decode_mimeheader() JIT/AOT — RFC 2047 (#6038)
--FILE--
<?php
$s = "日本語";
$enc = mb_encode_mimeheader($s, "UTF-8");
echo mb_decode_mimeheader($enc), "\n";
echo mb_encode_mimeheader("Hello 世界", "UTF-8"), "\n";
--EXPECT--
日本語
Hello =?UTF-8?B?5LiW55WM?=
