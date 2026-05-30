--TEST--
AOT: pathinfo() PATHINFO_* constants via LLVM (#3651)
--FILE--
<?php
echo pathinfo('/www/htdocs/index.html', PATHINFO_EXTENSION), "\n";
echo pathinfo('/www/htdocs/index.html', PATHINFO_BASENAME), "\n";
$info = pathinfo('/www/htdocs/index.html', PATHINFO_ALL);
echo $info['dirname'], "\n";
echo $info['filename'], "\n";
--EXPECT--
html
index.html
/www/htdocs
index
