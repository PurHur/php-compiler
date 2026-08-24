--TEST--
AOT: mb_encode_mimeheader()/mb_decode_mimeheader() runtime NestedJIT (#34299)
--FILE--
<?php
$parts = ['Hello ', '世界'];
$s = $parts[0] . $parts[1];
$enc = mb_encode_mimeheader($s);
echo $enc, "\n";
echo mb_decode_mimeheader($enc), "\n";
--EXPECT--
Hello =?UTF-8?B?5LiW55WM?=
Hello 世界
