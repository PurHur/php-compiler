--TEST--
stdlib addcslashes() stripcslashes() substr_replace() (issue #3356)
--FILE--
<?php
echo addcslashes("Zoo['.Foo]", 'A..z'), "\n";
echo stripcslashes('Qu\\\'ote'), "\n";
echo substr_replace('abcdef', 'X', 2, 2), "\n";
echo addcslashes('hello', 'a..z'), "\n";
echo stripcslashes('line1\\nline2'), "\n";
echo substr_replace('abc', 'X', 0, 0), "\n";
echo substr_replace('abc', 'X', 3, 0), "\n";
echo substr_replace('abc', 'X', 1, -1), "\n";
echo substr_replace('abcdef', 'X', 2), "\n";
echo substr_replace('', 'X', 0), "\n";
--EXPECT--
\Z\o\o\['.\F\o\o\]
Qu'ote
abXef
\h\e\l\l\o
line1
line2
Xabc
abcX
aXc
abX
X
