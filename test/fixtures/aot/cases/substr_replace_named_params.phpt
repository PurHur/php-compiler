--TEST--
AOT: substr_replace() named string:/replace:/offset:/length: arguments (#23183)
--FILE--
<?php
echo substr_replace(string: 'abcdef', replace: 'X', offset: 2, length: 1), "\n";
echo substr_replace(string: 'abcdef', replace: 'X', offset: 2), "\n";
--EXPECT--
abXdef
abX
