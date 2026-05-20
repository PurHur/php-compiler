--TEST--
stdlib exit() and die() JIT path
--FILE--
<?php
die("halt\n");
echo "never\n";
--EXPECT--
halt
