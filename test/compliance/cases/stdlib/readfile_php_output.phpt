--TEST--
stdlib readfile()/fpassthru() php://output passthru sentinel -1 (ext/standard/streams.c, #18417)
--FILE--
<?php
echo readfile('php://output'), "\n";
echo readfile('php://stdin'), "\n";
echo readfile('php://memory'), "\n";
$h = fopen('php://output', 'wb');
echo fpassthru($h), "\n";
--EXPECT--
-1
0
0
-1
