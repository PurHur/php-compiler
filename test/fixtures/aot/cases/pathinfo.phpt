--TEST--
AOT: pathinfo() via LLVM
--FILE--
<?php
echo pathinfo('/var/www/index.html', 4), "\n";
echo pathinfo('/var/www/index.html', 1), "\n";
$info = pathinfo('/var/www/index.html', 15);
echo $info['basename'], "\n";
--EXPECT--
html
/var/www
index.html
