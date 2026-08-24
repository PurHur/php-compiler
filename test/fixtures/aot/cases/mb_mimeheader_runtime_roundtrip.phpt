--TEST--
AOT: non-foldable mb_encode_mimeheader→decode UTF-8 roundtrip (#34310)
--FILE--
<?php
$parts = ['Hello ', '世界'];
$s = $parts[0].$parts[1];
$e = mb_encode_mimeheader($s);
echo $e, "\n";
echo mb_decode_mimeheader($e), "\n";
--EXPECT--
Hello =?UTF-8?B?5LiW55WM?=
Hello 世界
