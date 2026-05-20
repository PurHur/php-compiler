--TEST--
stdlib header() with replace flag (issue #51)
--FILE--
<?php
header('Content-Type: text/html', true);
header('Content-Type: application/json', false);
echo "ok\n";
--EXPECT--
ok
