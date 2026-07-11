--TEST--
stdlib get_cfg_var('doc_root') returns empty string not false (#12543, ext/standard/basic_functions.c)
--FILE--
<?php
$docRoot = get_cfg_var('doc_root');
echo gettype($docRoot).':'.($docRoot === '' ? 'empty' : 'bad')."\n";
echo get_cfg_var('bogus_cfg_xyz_12543') === false ? "unknown-false\n" : "unknown-bad\n";
--EXPECT--
string:empty
unknown-false
