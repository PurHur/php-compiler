--TEST--
stdlib header() rejects CR/LF in header line (issue #77)
--FILE--
<?php
header("X: a\r\nInjected: yes");
echo "fail\n";
--EXPECTREGEX--
header\(\) values must not contain CR or LF characters
