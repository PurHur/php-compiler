--TEST--
stdlib realpath_cache_get() JIT returns empty snapshot (#3463)
--SKIPIF--
<?php
if (!getenv('PHP_COMPILER_LLVM_PATH') && !is_dir(__DIR__ . '/../../../../.llvm')) {
    die('skip LLVM not available');
}
--FILE--
<?php
$size = realpath_cache_size();
$cache = realpath_cache_get();
echo $size === 0 ? "size_zero\n" : "size_other\n";
echo [] === $cache ? "cache_empty\n" : "cache_other\n";
--EXPECT--
size_zero
cache_empty
