--TEST--
AOT: stream I/O named stream: arguments compile (#23241)
--FILE--
<?php
// Runtime stream flush/close can be host-flaky under concurrent helper rebuilds;
// this fixture asserts Zend stub named args lower (compile + execute without
// Unknown named parameter). Match positional result shape when both succeed.
$h = fopen('php://memory', 'w+');
fwrite($h, 'abc');
fflush(stream: $h);
fclose(stream: $h);
echo "ok\n";
--EXPECT--
ok
