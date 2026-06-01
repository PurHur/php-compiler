--TEST--
stdlib pathinfo() combined PATHINFO_* flags (#3772)
--FILE--
<?php
$combo = pathinfo('/a/b.c', PATHINFO_DIRNAME | PATHINFO_EXTENSION);
echo $combo['dirname'], "\n";
echo $combo['extension'], "\n";
$pair = pathinfo('/a/b.c', PATHINFO_BASENAME | PATHINFO_FILENAME);
echo $pair['basename'], "\n";
echo $pair['filename'], "\n";
$three = pathinfo('/var/www/index.html', PATHINFO_DIRNAME | PATHINFO_BASENAME | PATHINFO_EXTENSION);
echo $three['dirname'], "\n";
echo $three['basename'], "\n";
echo $three['extension'], "\n";
--EXPECT--
/a
c
b.c
b
/var/www
index.html
html
