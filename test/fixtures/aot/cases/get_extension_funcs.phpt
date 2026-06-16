--TEST--
AOT get_extension_funcs() lists in-tree extension builtins (#3433, #9050)
--FILE--
<?php
$funcs = get_extension_funcs('hash');
echo count($funcs) > 0 ? "hash_ok\n" : "hash_bad\n";
echo get_extension_funcs('nonexistent_xyz_3433') === false ? "unknown_ok\n" : "unknown_bad\n";
$pcre = get_extension_funcs('pcre');
echo count($pcre) > 0 ? "pcre_ok\n" : "pcre_bad\n";
--EXPECT--
hash_ok
unknown_ok
pcre_ok
