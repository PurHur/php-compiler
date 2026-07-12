--TEST--
stdlib readfile() php://output passthru sentinel JIT (#18417, ext/standard/streams.c)
--JIT--
--FILE--
<?php
echo readfile('php://output'), "\n";
--EXPECT--
-1
