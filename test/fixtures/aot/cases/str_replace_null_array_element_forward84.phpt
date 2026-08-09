--TEST--
AOT: substr_replace null array element on 8.4 (#29309)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// str_replace()/str_ireplace() array args segfault under AOT on master (pre-existing);
// DEP absence is guarded on VM/JIT. This fixture covers the substr_replace AOT path we unblocked.
echo substr_replace('abc', [null], 1, 1) === 'ac' ? "ok\n" : "bad\n";
?>
--EXPECT--
ok
