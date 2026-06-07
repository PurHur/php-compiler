--TEST--
stdlib levenshtein() — numeric-string cost coercion without strict_types (#4190)
--FILE--
<?php
echo levenshtein('a', 'b', '2', '1', '1'), "\n";
--EXPECT--
1
