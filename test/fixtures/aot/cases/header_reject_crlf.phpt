--TEST--
AOT: header() drops dynamic lines with embedded CR/LF (issue #77)
--FILE--
<?php
$inject = "\r\nInjected: yes";
header('X-Safe: ok'.$inject);
echo "ok\n";
--EXPECT--
ok
