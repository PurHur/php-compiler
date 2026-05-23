--TEST--
stdlib base64_encode() JIT
--FILE--
<?php
echo base64_encode('foo'), "\n";
--EXPECT--
Zm9v