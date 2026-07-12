--TEST--
stdlib encoding/binary builtins — null $string TypeError (#18252, ext/standard/string.c, base64.c)
--FILE--
<?php
foreach (['bin2hex', 'base64_encode', 'quoted_printable_encode', 'quoted_printable_decode'] as $fn) {
    try {
        $fn(null);
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
bin2hex(): Argument #1 ($string) must be of type string, null given
base64_encode(): Argument #1 ($string) must be of type string, null given
quoted_printable_encode(): Argument #1 ($string) must be of type string, null given
quoted_printable_decode(): Argument #1 ($string) must be of type string, null given
