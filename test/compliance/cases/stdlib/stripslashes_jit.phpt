--TEST--
stdlib stripslashes() JIT
--FILE--
<?php
echo stripslashes("it\\'s"), "\n";
--EXPECT--
it's
