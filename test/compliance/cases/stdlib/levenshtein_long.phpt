--TEST--
stdlib levenshtein() — strings longer than 255 bytes (#4150)
--FILE--
<?php
echo levenshtein(str_repeat('a', 300), str_repeat('b', 300)), "\n";
echo levenshtein(str_repeat('x', 256), str_repeat('y', 256)), "\n";
--EXPECT--
300
256
