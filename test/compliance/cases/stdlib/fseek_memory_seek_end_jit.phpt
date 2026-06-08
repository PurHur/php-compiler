--TEST--
fseek() SEEK_END with negative offset on php://memory JIT (#6702, __compiler_fseek)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
fwrite($f, 'abc');
fseek($f, -1, SEEK_END);
var_dump(ftell($f), fgetc($f));
--EXPECT--
int(2)
string(1) "c"
