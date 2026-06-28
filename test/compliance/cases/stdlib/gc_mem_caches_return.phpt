--TEST--
stdlib gc_mem_caches() returns Zend MM cache on first call (#9160, #12071, #12921)
--FILE--
<?php
$binary = defined('PHP_BINARY') ? PHP_BINARY : 'php';
$probe = (int) trim((string) shell_exec($binary . ' -n -r ' . escapeshellarg('echo gc_mem_caches();')));
$first = gc_mem_caches();
$second = gc_mem_caches();
echo ($first === $probe && 0 === $second) ? "ok\n" : "fail first=$first probe=$probe second=$second\n";
--EXPECT--
ok
