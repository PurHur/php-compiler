--TEST--
Language: hex integer literals with a-f digits compile (#7140)
--FILE--
<?php
var_dump(0xFF);
var_dump((~ord('a')) & 0xFF);
var_dump(0xAB);
var_dump(0xDEADBEEF);
var_dump(0x1A);
--EXPECT--
int(255)
int(158)
int(171)
int(3735928559)
int(26)
