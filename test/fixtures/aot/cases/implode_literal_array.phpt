--TEST--
AOT: implode() with inline array literal
--FILE--
<?php
echo implode('|', ['a', 'b', 'c']), "\n";
--EXPECT--
a|b|c
