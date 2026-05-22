--TEST--
stdlib strpbrk() JIT
--FILE--
<?php
echo strpbrk("path/to/file.txt", "/"), "\n";
--EXPECT--
/to/file.txt
