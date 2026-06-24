--TEST--
stdlib clearstatcache() clear_realpath_cache: named parameter (#11348, ext/standard/filestat.c)
--FILE--
<?php
realpath(__DIR__);
clearstatcache(clear_realpath_cache: true);
realpath(__DIR__);
echo count(realpath_cache_get()) >= 1 ? "named ok\n" : "named fail\n";
clearstatcache(true);
echo "positional ok\n";
--EXPECT--
named ok
positional ok
