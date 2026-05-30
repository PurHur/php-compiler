--TEST--
AOT: pathinfo() combined PATHINFO_* flags (#3772)
--FILE--
<?php
$combo = pathinfo('/a/b.c', PATHINFO_DIRNAME | PATHINFO_EXTENSION);
echo $combo['dirname'], "\n";
echo $combo['extension'], "\n";
$pair = pathinfo('/www/htdocs/index.html', PATHINFO_EXTENSION | PATHINFO_FILENAME);
echo $pair['extension'], "\n";
echo $pair['filename'], "\n";
--EXPECT--
/a
c
html
index
