--TEST--
stream_get_wrappers() — compress.zlib scheme name JIT (#10642)
--FILE--
<?php
$wrappers = stream_get_wrappers();
var_export(in_array('compress.zlib', $wrappers, true));
echo "\n";
var_export(in_array('zlib', $wrappers, true));
echo "\n";
--EXPECT--
true
false
