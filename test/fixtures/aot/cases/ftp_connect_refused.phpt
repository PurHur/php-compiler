--TEST--
ftp_connect AOT refused-connect guard (#27393)
--FILE--
<?php
$c = @ftp_connect("127.0.0.1", 21, 1);
var_dump($c === false || is_resource($c) || is_object($c));
--EXPECT--
bool(true)
