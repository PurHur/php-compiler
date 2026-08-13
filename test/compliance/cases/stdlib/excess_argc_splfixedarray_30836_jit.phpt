--TEST--
SplFixedArray fromArray/toArray/setSize excess argc → ArgumentCountError JIT (#30836)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php if (!getenv('PHP_COMPILER_LLVM_PATH') && !is_dir(__DIR__ . '/../../../../.llvm')) echo "skip LLVM not available\n"; ?>
--RUNFILE--
../../../repro/maintainer_gap_splfixedarray_excess_argc_30836.php
--EXPECT--
fromArray: SplFixedArray::fromArray() expects at most 2 arguments, 3 given
toArray: SplFixedArray::toArray() expects exactly 0 arguments, 1 given
setSize: SplFixedArray::setSize() expects exactly 1 argument, 2 given
getSize: SplFixedArray::getSize() expects exactly 0 arguments, 1 given
ok_fromArray: 7
ok_toArray: 1
ok_setSize: 1
