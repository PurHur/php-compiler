--TEST--
AOT: nl2br() — scalar subject + string use_xhtml (#4293)
--FILE--
<?php
echo nl2br("hello"), "\n";
echo nl2br("a\nb", "x"), "\n";
echo nl2br("a\nb", "false"), "\n";
echo nl2br("a\nb", "0"), "\n";
--EXPECT--
hello
a<br />
b
a<br />
b
a<br>
b
