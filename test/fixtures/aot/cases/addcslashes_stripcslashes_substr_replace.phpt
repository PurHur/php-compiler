--TEST--
AOT addcslashes() stripcslashes() substr_replace() (issue #3356)
--FILE--
<?php
echo addcslashes("Zoo['.Foo]", 'A..z'), "\n";
echo stripcslashes('Qu\\\'ote'), "\n";
echo substr_replace('abcdef', 'X', 2, 2), "\n";
--EXPECT--
\Z\o\o\['.\F\o\o\]
Qu'ote
abXef
