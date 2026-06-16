--TEST--
AOT: mb_encode_mimeheader()/mb_decode_mimeheader() — RFC 2047 (#6038)
--FILE--
<?php
echo mb_decode_mimeheader("=?UTF-8?B?5pel5pys6Kqe?="), "\n";
echo mb_encode_mimeheader("Hello 世界", "UTF-8"), "\n";
--EXPECT--
日本語
Hello =?UTF-8?B?5LiW55WM?=
