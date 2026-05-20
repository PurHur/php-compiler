--TEST--
stdlib ucwords() custom separators (VM)
--FILE--
<?php
echo ucwords('foo|bar', '|'), "\n";
echo ucwords('2nd story', ' '), "\n";
--EXPECT--
Foo|Bar
2nd Story
