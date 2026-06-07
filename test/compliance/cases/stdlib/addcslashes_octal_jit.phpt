--TEST--
stdlib addcslashes() JIT — control bytes use octal/named escapes (#4736)
--FILE--
<?php
var_export(addcslashes("\x05\x06", "\x05-\x06"));
echo "\n";
var_export(addcslashes("a\x00b", "\0"));
echo "\n";
var_export(addcslashes("a\tb", "\t"));
echo "\n";
--EXPECT--
'\\005\\006'
'a\\000b'
'a\\tb'
