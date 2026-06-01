--TEST--
stdlib pathinfo() combined PATHINFO_* flags — single string by priority (#4049)
--FILE--
<?php
$combo = pathinfo('/a/b.c', PATHINFO_DIRNAME | PATHINFO_EXTENSION);
echo $combo, "\n";
$pair = pathinfo('/a/b.c', PATHINFO_BASENAME | PATHINFO_FILENAME);
echo $pair, "\n";
$three = pathinfo('/var/www/index.html', PATHINFO_DIRNAME | PATHINFO_BASENAME | PATHINFO_EXTENSION);
echo $three, "\n";
$extFn = pathinfo('/www/htdocs/index.html', PATHINFO_EXTENSION | PATHINFO_FILENAME);
echo $extFn, "\n";
$all = pathinfo('/var/www/index.html', PATHINFO_ALL);
echo $all['dirname'], "\n";
echo $all['extension'], "\n";
--EXPECT--
/a
b.c
/var/www
html
/var/www
html
