--TEST--
stdlib addcslashes() — control bytes use octal/named escapes (#4736, php-src string.c)
--FILE--
<?php
var_export(addcslashes("\x05\x06", "\x05-\x06"));
echo "\n";
var_export(addcslashes("a\x00b", "\0"));
echo "\n";
var_export(addcslashes("a\tb", "\t"));
echo "\n";
echo bin2hex(stripcslashes(addcslashes("a\x00b", "\0"))), "\n";
--EXPECT--
'\\005\\006'
'a\\000b'
'a\\tb'
610062
