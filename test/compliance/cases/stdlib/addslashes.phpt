--TEST--
stdlib addslashes()
--FILE--
<?php
echo addslashes(''), "\n";
echo addslashes('plain'), "\n";
echo addslashes("O'Reilly"), "\n";
echo addslashes('say "hello"'), "\n";
echo addslashes("back\\slash"), "\n";
echo bin2hex(addslashes('a' . chr(0) . 'b')), "\n";
--EXPECT--
plain
O\'Reilly
say \"hello\"
back\\slash
615c3062
