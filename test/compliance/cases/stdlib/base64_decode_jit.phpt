--TEST--
stdlib base64_decode() JIT
--FILE--
<?php
echo base64_decode('Zm9v'), "\n";
echo base64_encode(base64_decode('Zm9v')), "\n";
--EXPECT--
foo
Zm9v