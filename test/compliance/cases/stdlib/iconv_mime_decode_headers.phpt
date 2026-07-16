--TEST--
stdlib iconv_mime_decode_headers() — RFC 822 header block decode (#19448, ext/iconv/iconv.c)
--FILE--
<?php
echo function_exists('iconv_mime_decode_headers') ? "fn yes\n" : "fn no\n";
$headers = "From: =?UTF-8?B?SGVsbG8=?= <a@b.c>\r\nSubject: =?UTF-8?Q?Test?= world\r\n\r\n";
var_export(iconv_mime_decode_headers($headers));
echo "\n";
$dup = "X-A: one\r\nX-A: two\r\n";
var_export(iconv_mime_decode_headers($dup));
echo "\n";
$folded = "Subject: =?UTF-8?Q?Hello?=\r\n world\r\n";
var_export(iconv_mime_decode_headers($folded));
echo "\n";
$bad = "From: ok\r\nBad: =?BAD?X?foo?=\r\nGood: yes\r\n";
var_export(iconv_mime_decode_headers($bad, ICONV_MIME_DECODE_STRICT));
echo "\n";
var_export(iconv_mime_decode_headers($bad, ICONV_MIME_DECODE_CONTINUE_ON_ERROR));
echo "\n";
enum E: string { case A = "From: x\r\n"; }
try {
    iconv_mime_decode_headers(E::A);
    echo "enum ok\n";
} catch (TypeError $e) {
    echo "enum TypeError\n";
}
?>
--EXPECT--
fn yes
array (
  'From' => 'Hello <a@b.c>',
  'Subject' => 'Test world',
)
array (
  'X-A' => array (
    0 => 'one',
    1 => 'two',
  ),
)
array (
  'Subject' => 'Helloworld',
)
false
array (
  'From' => 'ok',
  'Bad' => '=?BAD?X?foo?=',
  'Good' => 'yes',
)
enum TypeError
