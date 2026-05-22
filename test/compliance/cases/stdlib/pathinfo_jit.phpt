--TEST--
stdlib pathinfo() JIT (PATHINFO_EXTENSION and PATHINFO_ALL)
--FILE--
<?php
echo pathinfo('/assets/app.js', 4), "\n";
echo pathinfo('/assets/app.js', 2), "\n";
$info = pathinfo('/var/www/index.html', 15);
echo $info['extension'], "\n";
echo $info['filename'], "\n";
--EXPECT--
js
app.js
html
index
