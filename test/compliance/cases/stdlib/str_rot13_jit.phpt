--TEST--
stdlib str_rot13() JIT
--FILE--
<?php
echo str_rot13('route'), "\n";
--EXPECT--
ebhgr
