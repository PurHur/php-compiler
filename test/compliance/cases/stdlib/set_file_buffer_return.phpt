--TEST--
stdlib set_file_buffer() returns prior write-buffer size on php://memory (#12532, php-src streamsfuncs.c)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
var_export(set_file_buffer($f, 0));
echo "\n";
var_export(stream_set_write_buffer($f, 8192));
echo "\n";
var_export(set_file_buffer($f, 0));
echo "\n";
--EXPECT--
-1
-1
-1
