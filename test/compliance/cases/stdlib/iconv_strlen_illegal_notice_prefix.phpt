--TEST--
stdlib iconv_strlen/iconv_substr illegal-char notice uses callee name (#25099, ext/iconv/iconv.c)
--FILE--
<?php
$msgs = [];
set_error_handler(static function (int $errno, string $message) use (&$msgs): bool {
    if (E_NOTICE === $errno || E_WARNING === $errno) {
        $msgs[] = $message;
    }
    return true;
});
var_export(iconv_strlen("\x80", 'UTF-8'));
echo "\n";
var_export(iconv_substr("\x80", 0, 1, 'UTF-8'));
echo "\n";
var_export(iconv_strpos("\x80", 'a', 0, 'UTF-8'));
echo "\n";
var_export(iconv_strrpos("\x80", 'a', 'UTF-8'));
echo "\n";
var_export(iconv_strlen('a', 'NOTANENC'));
echo "\n";
echo implode("\n", $msgs), "\n";
?>
--EXPECT--
false
false
false
false
false
iconv_strlen(): Detected an illegal character in input string
iconv_substr(): Detected an illegal character in input string
iconv_strlen(): Wrong encoding, conversion from "NOTANENC" to "UCS-4LE" is not allowed
