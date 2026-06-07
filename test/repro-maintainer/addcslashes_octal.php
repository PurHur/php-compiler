<?php
// Issue #4736: addcslashes() control-byte output must use C-style escapes (php-src php_addcslashes_str).
var_export(addcslashes("\x05\x06", "\x05-\x06"));
echo "\n";
var_export(addcslashes("a\x00b", "\0"));
echo "\n";
var_export(addcslashes("a\tb", "\t"));
echo "\n";
