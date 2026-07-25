--TEST--
AOT: strpos/stripos/strrpos named haystack/needle arguments (#23182)
--FILE--
<?php
echo strpos(haystack: 'abcdef', needle: 'cd'), "\n";
echo stripos(haystack: 'ABCDEF', needle: 'cd'), "\n";
echo strrpos(haystack: 'ab cd cd', needle: 'cd'), "\n";
--EXPECT--
2
2
6
