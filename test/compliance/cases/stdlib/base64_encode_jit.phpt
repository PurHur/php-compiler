--TEST--
stdlib base64_encode() JIT path
--FILE--
<?php
echo base64_encode('abc'), "\n";
echo base64_encode(base64_decode('YQ==')), "\n";
echo base64_encode('hello'), "\n";
--EXPECT--
YWJj
YQ==
aGVsbG8=
