--TEST--
fopen() named mode argument (VM, issue #6747)
--FILE--
<?php
$fp = fopen('php://memory', mode: 'r+');
var_dump(is_resource($fp));
fclose($fp);
--EXPECT--
bool(true)
