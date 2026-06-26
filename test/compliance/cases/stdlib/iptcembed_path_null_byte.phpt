--TEST--
stdlib iptcembed() binary path operand must ValueError (php-src Z_PARAM_PATH, #12312)
--FILE--
<?php
$jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
try {
    iptcembed('', $jpeg);
    echo "no-exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
iptcembed(): Argument #2 ($filename) must not contain any null bytes
