--TEST--
stdlib stripos() JIT not found
--FILE--
<?php
echo stripos('Hello', 'xyz') == false ? 'y' : 'n', "\n";
--EXPECT--
y
