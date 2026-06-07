--TEST--
stdlib fsync()/fdatasync() JIT warn on unsyncable streams (#7339)
--FILE--
<?php
$fp = fopen('php://memory', 'w');
fwrite($fp, 'x');
var_export(fsync($fp));
echo "\n";
--EXPECT--
PHP Warning:  fsync(): Can't fsync this stream!
false
