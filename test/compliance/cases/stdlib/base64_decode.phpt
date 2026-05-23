--TEST--
stdlib base64_decode()
--FILE--
<?php
echo base64_encode(base64_decode('')), "\n";
echo base64_encode(base64_decode('YQ==')), "\n";
echo base64_encode(base64_decode(' YQ== ')), "\n";
echo base64_encode(base64_decode('aGVsbG8=')), "\n";
echo base64_encode(base64_decode('!!!')), "\n";
echo base64_encode(base64_decode('YWJj')), "\n";
--EXPECT--

YQ==
YQ==
aGVsbG8=

YWJj
