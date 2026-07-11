--TEST--
stdlib stream_get_meta_data() mode reports user fopen mode (#13021)
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'meta');
$f = fopen($path, 'r+');
$mode = stream_get_meta_data($f)['mode'];
fclose($f);
unlink($path);
var_export($mode === 'r+');
echo "\n";
--EXPECT--
true
