--TEST--
VM: fopen php://input reads REQUEST_BODY without host @fopen
--FILE--
<?php
$h = fopen('php://input', 'r');
var_dump($h !== false);
echo fread($h, 100), "\n";
fclose($h);
--ENV--
REQUEST_BODY=hello-body
--EXPECT--
bool(true)
hello-body

--FILE--
<?php
$h = fopen('php://output', 'w');
var_dump($h !== false);
fwrite($h, 'out-data');
fclose($h);
--EXPECT--
bool(true)
out-data
