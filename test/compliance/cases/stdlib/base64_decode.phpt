--TEST--
stdlib base64_decode()
--FILE--
<?php
echo base64_decode(''), "\n";
echo base64_decode('Zg=='), "\n";
echo base64_decode('Zm8='), "\n";
echo base64_decode('Zm9v'), "\n";
echo base64_decode("Zm9v\n"), "\n";
--EXPECT--

f
fo
foo
foo