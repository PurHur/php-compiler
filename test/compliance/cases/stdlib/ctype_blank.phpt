--TEST--
stdlib ctype_blank() tab/space only (issue #3381, php-src ext/ctype/ctype.c)
--FILE--
<?php
echo (int) ctype_blank(" \t"), "\n";
echo (int) ctype_blank("\t"), "\n";
echo (int) ctype_blank(" "), "\n";
echo (int) ctype_blank(""), "\n";
echo (int) ctype_blank("\n"), "\n";
echo (int) ctype_blank("a"), "\n";
echo (int) ctype_blank(9), "\n";
echo (int) ctype_blank(32), "\n";
echo (int) ctype_blank(10), "\n";
?>
--EXPECT--
1
1
1
0
0
0
1
1
0
