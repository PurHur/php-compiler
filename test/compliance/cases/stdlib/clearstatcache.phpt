--TEST--
stdlib clearstatcache()
--FILE--
<?php
clearstatcache();
clearstatcache(false);
clearstatcache(true, 'test/compliance/cases/stdlib/clearstatcache_fixture.txt');
echo "ok\n";
--EXPECT--
ok
