--TEST--
AOT: readlink/linkinfo named Zend stub path (#23944)
--FILE--
<?php
$link = 'test/compliance/cases/stdlib/is_link_fixture/link';
echo linkinfo(path: $link) > 0 ? "ok\n" : "fail\n";
echo (false === @readlink(path: '/no/such/phpc-readlink-23944')) ? "missing\n" : "present\n";
--EXPECT--
ok
missing
