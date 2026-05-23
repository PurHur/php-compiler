--TEST--
stdlib base64_encode()
--FILE--
<?php
echo base64_encode(''), "\n";
echo base64_encode('f'), "\n";
echo base64_encode('fo'), "\n";
echo base64_encode('foo'), "\n";
echo base64_encode("\x00\xff"), "\n";
--EXPECT--

Zg==
Zm8=
Zm9v
AP8=