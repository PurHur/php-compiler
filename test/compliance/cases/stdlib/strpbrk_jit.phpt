--TEST--
stdlib strpbrk() JIT
--FILE--
<?php
echo strpbrk('path/to/file', '/'), "\n";
--EXPECT--
/to/file
