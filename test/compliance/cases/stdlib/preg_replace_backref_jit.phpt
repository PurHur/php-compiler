--TEST--
JIT: preg_replace() numeric capture backreferences (#9599, ext/pcre/php_pcre.c)
--FILE--
<?php
echo preg_replace('/([0-9]+)/', '[$1]', 'x12y'), "\n";
echo preg_replace('/(\d)/', '${1}x', 'a9b'), "\n";
echo preg_replace('/(.)(.)/', '$2$1', 'ab'), "\n";
?>
--EXPECT--
x[12]y
a9xb
ba
