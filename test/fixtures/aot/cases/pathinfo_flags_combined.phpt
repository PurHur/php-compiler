--TEST--
AOT: pathinfo() combined PATHINFO_* flags — single string by priority (#4049)
--FILE--
<?php
$combo = pathinfo('/a/b.c', PATHINFO_DIRNAME | PATHINFO_EXTENSION);
echo $combo, "\n";
$pair = pathinfo('/www/htdocs/index.html', PATHINFO_EXTENSION | PATHINFO_FILENAME);
echo $pair, "\n";
$all = pathinfo('/var/www/index.html', PATHINFO_ALL);
echo $all['dirname'], "\n";
echo $all['extension'], "\n";
--EXPECT--
/a
html
/var/www
html
