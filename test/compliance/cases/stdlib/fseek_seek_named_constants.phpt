--TEST--
fseek() SEEK_* named constants + constant() lookup (#9610, ext/standard/streams.c)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
fwrite($fp, 'hello');
fseek($fp, 0, SEEK_END);
var_dump(ftell($fp));
fseek($fp, -2, SEEK_CUR);
var_dump(fread($fp, 10));
$whence = constant('SEEK_END');
fseek($fp, 0, $whence);
var_dump(ftell($fp));
var_dump(defined('SEEK_SET'), SEEK_SET, SEEK_CUR, SEEK_END);
--EXPECT--
int(5)
string(2) "lo"
int(5)
bool(true)
int(0)
int(1)
int(2)
