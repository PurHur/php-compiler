--TEST--
stdlib addslashes() JIT
--FILE--
<?php
echo addslashes("it's"), "\n";
--EXPECT--
it\'s
