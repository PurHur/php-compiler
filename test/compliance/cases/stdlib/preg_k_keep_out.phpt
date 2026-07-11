--TEST--
stdlib preg_match()/preg_replace() \\K keep-out assertion (issue #14089, ext/pcre/php_pcre.c)
--FILE--
<?php
preg_match('/(a)\K(b)/', 'ab', $m);
echo $m[0], "\n";
echo $m[1], "\n";
echo $m[2], "\n";
echo preg_replace('/(a)\K(b)/', 'X', 'ab'), "\n";
--EXPECT--
b
a
b
aX
