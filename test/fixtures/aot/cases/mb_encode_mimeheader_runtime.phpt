--TEST--
AOT: mb_encode_mimeheader()/mb_decode_mimeheader() runtime strings (#34299)
--FILE--
<?php
$s = 'Hello 世界';
echo mb_encode_mimeheader($s, 'UTF-8'), "\n";
$e = 'Hello =?UTF-8?B?5LiW55WM?=';
echo mb_decode_mimeheader($e), "\n";
--EXPECT--
Hello =?UTF-8?B?5LiW55WM?=
Hello 世界
