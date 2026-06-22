--TEST--
stdlib addslashes()/stripslashes() NUL C-escape (#10634)
--FILE--
<?php
echo bin2hex(addslashes("a\0b")), "\n";
echo bin2hex(stripslashes('a\\0b')), "\n";
echo bin2hex(stripslashes(addslashes("x\0y"))), "\n";
--EXPECT--
615c3062
610062
780079
