--TEST--
stdlib is_null() JIT string and bool
--FILE--
<?php
echo is_null('') ? 'y' : 'n', "\n";
echo is_null(true) ? 'y' : 'n', "\n";
--EXPECT--
n
n
