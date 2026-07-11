--TEST--
stdlib mb_encode_mimeheader()/mb_decode_mimeheader() — RFC 2047 round-trip (#6038, ext/mbstring/mbstring.c)
--FILE--
<?php
echo function_exists('mb_encode_mimeheader') ? "encode yes\n" : "encode no\n";
echo function_exists('mb_decode_mimeheader') ? "decode yes\n" : "decode no\n";
$s = "日本語";
$enc = mb_encode_mimeheader($s, "UTF-8");
echo $enc, "\n";
echo mb_decode_mimeheader($enc), "\n";
echo mb_encode_mimeheader("hello", "UTF-8"), "\n";
echo mb_decode_mimeheader("hello"), "\n";
echo mb_encode_mimeheader("Hello 世界", "UTF-8"), "\n";
echo mb_decode_mimeheader(mb_encode_mimeheader("Hello 世界", "UTF-8")), "\n";
echo mb_encode_mimeheader("über", "UTF-8"), "\n";
echo mb_decode_mimeheader(mb_encode_mimeheader("über", "UTF-8")), "\n";
echo mb_encode_mimeheader("", "UTF-8") === "" ? "empty ok\n" : "empty fail\n";
$q = mb_encode_mimeheader($s, "UTF-8", "Q");
echo mb_decode_mimeheader($q), "\n";
--EXPECT--
encode yes
decode yes
=?UTF-8?B?5pel5pys6Kqe?=
日本語
hello
hello
Hello =?UTF-8?B?5LiW55WM?=
Hello 世界
=?UTF-8?B?w7xiZXI=?=
über
empty ok
日本語
