--TEST--
iconv_mime_encode(null) $options TypeError under JIT (#31310, ext/iconv/iconv.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    var_export(iconv_mime_encode('s', 'b', null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo iconv_mime_encode('s', 'b'), "\n";
?>
--EXPECT--
iconv_mime_encode(): Argument #3 ($options) must be of type array, null given
s: =?UTF-8?B?Yg==?=
