--TEST--
stdlib substr_count() negative offset and length
--FILE--
<?php
echo substr_count('abcabc', 'bc', -1), "\n";
echo substr_count('abcabc', 'bc', -3), "\n";
echo substr_count('abcabc', 'bc', 0, -1), "\n";
--EXPECT--
0
1
1
