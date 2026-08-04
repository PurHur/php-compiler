--TEST--
AOT: thin var_export(string) quotes/escapes without NestedJIT addslashes (#27574)
--FILE--
<?php
var_export('hello');
echo "\n";
var_export("a'b");
echo "\n";
var_export('a\\b');
echo "\n";
var_export("a\0b");
echo "\n";
--EXPECT--
'hello'
'a\'b'
'a\\b'
'a' . "\0" . 'b'
