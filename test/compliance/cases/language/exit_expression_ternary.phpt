--TEST--
Language: exit expression in ternary false branch is not evaluated (#3539)
--FILE--
<?php
echo 'value=', (false ? (exit) : 42), "\n";
--EXPECT--
value=42
