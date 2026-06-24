--TEST--
stdlib gc_mem_caches() returns non-zero on first call (#9160)
--FILE--
<?php
$first = gc_mem_caches();
$second = gc_mem_caches();
echo 'first=', ($first > 0 ? 'nonzero' : 'zero'), "\n";
echo 'second=', ($second > 0 ? 'nonzero' : 'zero'), "\n";
--EXPECT--
first=nonzero
second=zero
