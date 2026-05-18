--TEST--
stdlib header() with string argument
--FILE--
<?php
header('X-Compiler-Test: 1');
echo "done\n";
--EXPECT--
done

