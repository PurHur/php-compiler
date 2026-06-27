--TEST--
stdlib gc_mem_caches() returns Zend MM cache on first call (#9160, #12071)
--FILE--
<?php
$first = gc_mem_caches();
$second = gc_mem_caches();
echo 'first=', $first, "\n";
echo 'second=', $second, "\n";
--EXPECT--
first=57344
second=0
