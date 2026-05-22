--TEST--
stdlib strrchr() JIT
--FILE--
<?php
echo strrchr("path/to/file.txt", "/"), "\n";
--EXPECT--
/file.txt
