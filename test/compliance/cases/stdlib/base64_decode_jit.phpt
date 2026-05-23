--TEST--
stdlib base64_decode() JIT path
--FILE--
<?php
echo base64_encode(base64_decode('YWJj')), "\n";
echo base64_encode(base64_decode(' YQ== ')), "\n";
echo base64_encode(base64_decode('!!!')), "\n";
--EXPECT--
YWJj
YQ==

