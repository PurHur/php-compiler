--TEST--
var_dump(ftell(), fgetc()) sibling chain after stream setup (#16254)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
fwrite($f, 'abc');
fseek($f, -1, SEEK_END);
var_dump(ftell($f), fgetc($f));
?>
--EXPECT--
int(2)
string(1) "c"
