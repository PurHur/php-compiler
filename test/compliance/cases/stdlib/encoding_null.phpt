--TEST--
stdlib encoding/binary builtins — null $string coerces to empty string (#18262, ext/standard/string.c, base64.c)
--FILE--
<?php
foreach (['bin2hex', 'base64_encode', 'quoted_printable_encode', 'quoted_printable_decode'] as $fn) {
    var_dump($fn(null));
}
--EXPECT--
string(0) ""
string(0) ""
string(0) ""
string(0) ""
