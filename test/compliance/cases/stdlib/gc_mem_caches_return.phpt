--TEST--
stdlib gc_mem_caches() returns Zend MM cache on first call (#9160, #12071, #12921, #23835)
--FILE--
<?php
$first = gc_mem_caches();
$second = gc_mem_caches();
echo ($first > 0 && 0 === $second) ? "ok\n" : "fail first=$first second=$second\n";
--EXPECT--
ok
