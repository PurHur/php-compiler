--TEST--
stdlib pathinfo() PATHINFO_* predefined constants (#3651)
--FILE--
<?php
echo pathinfo('/www/htdocs/index.html', PATHINFO_EXTENSION), "\n";
echo pathinfo('/www/htdocs/index.html', PATHINFO_FILENAME), "\n";
echo pathinfo('/www/htdocs/index.html', PATHINFO_BASENAME), "\n";
echo pathinfo('/www/htdocs/index.html', PATHINFO_DIRNAME), "\n";
$all = pathinfo('/www/htdocs/index.html');
echo $all['extension'], "\n";
echo $all['filename'], "\n";
$constants = get_defined_constants(true);
echo isset($constants['Core']['PATHINFO_EXTENSION']) && $constants['Core']['PATHINFO_EXTENSION'] === 4 ? "ext_ok\n" : "ext_bad\n";
echo isset($constants['Core']['PATHINFO_ALL']) && $constants['Core']['PATHINFO_ALL'] === 15 ? "all_ok\n" : "all_bad\n";
--EXPECT--
html
index
index.html
/www/htdocs
html
index
ext_ok
all_ok
