--TEST--
stdlib iconv_mime_decode()/iconv_mime_encode() — RFC 2047 helpers (#6364, ext/iconv/iconv.c)
--FILE--
<?php
echo function_exists('iconv_mime_decode') ? "decode yes\n" : "decode no\n";
echo function_exists('iconv_mime_encode') ? "encode yes\n" : "encode no\n";
var_export(iconv_mime_decode('=?UTF-8?B?SGk=?=', 0, 'UTF-8'));
echo "\n";
var_export(iconv_mime_decode('=?ISO-8859-1?B?6Q==?=', 0, 'UTF-8'));
echo "\n";
var_export(iconv_mime_decode('not mime', 0, 'UTF-8'));
echo "\n";
var_export(iconv_mime_decode('=?BAD?X?foo?=', ICONV_MIME_DECODE_STRICT, 'UTF-8'));
echo "\n";
var_export(iconv_mime_decode('=?BAD?X?foo?=', ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8'));
echo "\n";
var_export(iconv_mime_encode('Subject', 'über', ['input-charset' => 'UTF-8', 'output-charset' => 'UTF-8']));
echo "\n";
var_export(iconv_mime_encode('X', 'hello', ['scheme' => 'Q', 'input-charset' => 'UTF-8', 'output-charset' => 'UTF-8']));
echo "\n";
?>
--EXPECT--
decode yes
encode yes
'Hi'
'é'
'not mime'
false
'=?BAD?X?foo?='
'Subject: =?UTF-8?B?w7xiZXI=?='
'X: =?UTF-8?Q?hello?='
