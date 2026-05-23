--TEST--
stdlib stripslashes()
--FILE--
<?php
echo stripslashes(''), "\n";
echo stripslashes('plain'), "\n";
echo stripslashes("O\\'Reilly"), "\n";
echo stripslashes('say \\"hello\\"'), "\n";
echo stripslashes("back\\\\slash"), "\n";
echo stripslashes(addslashes("O'Reilly")), "\n";
echo stripslashes('a\\b'), "\n";
echo stripslashes('a\\\\b'), "\n";
--EXPECT--
plain
O'Reilly
say "hello"
back\slash
O'Reilly
a\b
a\\b
