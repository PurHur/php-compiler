--TEST--
AOT: mb_encode_mimeheader()/mb_decode_mimeheader() runtime strings (#34299)
--FILE--
<?php
$s = 'Hello 世界';
$enc = mb_encode_mimeheader($s, 'UTF-8');
echo $enc, "\n";
echo mb_decode_mimeheader($enc), "\n";
--EXPECT--
Hello =?UTF-8?B?5LiW55WM?=
Hello 世界
