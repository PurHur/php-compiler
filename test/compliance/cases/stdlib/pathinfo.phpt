--TEST--
stdlib pathinfo() components and PATHINFO_ALL
--FILE--
<?php
echo pathinfo('/var/www/index.html', 4), "\n";
echo pathinfo('/var/www/index.html', 8), "\n";
echo pathinfo('archive.tar.gz', 4), "\n";
echo pathinfo('noext', 4), "\n";
$all = pathinfo('/var/www/public/index.php', 15);
echo $all['dirname'], "\n";
echo $all['basename'], "\n";
echo $all['extension'], "\n";
echo $all['filename'], "\n";
--EXPECT--
html
index
gz

/var/www/public
index.php
php
index
