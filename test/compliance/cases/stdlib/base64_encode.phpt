--TEST--
stdlib base64_encode()
--FILE--
<?php
echo base64_encode(''), "\n";
echo base64_encode('a'), "\n";
echo base64_encode('ab'), "\n";
echo base64_encode('abc'), "\n";
echo base64_encode("\x00\xff"), "\n";
echo base64_encode('hello'), "\n";
--EXPECT--

YQ==
YWI=
YWJj
AP8=
aGVsbG8=
