--TEST--
AOT: str_ends_with() via LLVM
--FILE--
<?php
echo str_ends_with('hello', 'lo') ? 'y' : 'n', "\n";
echo str_ends_with('hello', 'he') ? 'y' : 'n', "\n";
echo str_ends_with('hi', 'hello') ? 'y' : 'n', "\n";
--EXPECT--
y
n
n
