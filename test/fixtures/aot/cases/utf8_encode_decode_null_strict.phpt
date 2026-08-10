--TEST--
AOT: utf8_encode/utf8_decode(null) TypeError under strict_types (#29889, ext/standard/utf8.c)
--FILE--
<?php
declare(strict_types=1);
try {
    echo utf8_encode(null), "\n";
    echo "encode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo utf8_decode(null), "\n";
    echo "decode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
utf8_encode(): Argument #1 ($string) must be of type string, null given
utf8_decode(): Argument #1 ($string) must be of type string, null given
