--TEST--
stdlib mb_stripos()/mb_strrpos()/mb_strrichr() multibyte search (VM, #7015)
--FILE--
<?php
foreach (['mb_stripos', 'mb_strrpos', 'mb_strrichr'] as $fn) {
    echo $fn, ': ', function_exists($fn) ? 'yes' : 'no', "\n";
}
echo mb_stripos('Hello World', 'world', 0, 'UTF-8'), "\n";
echo mb_strrpos('Hello World', 'o', 0, 'UTF-8'), "\n";
echo mb_strrichr('Hello World', 'WORLD'), "\n";
var_export(mb_stripos('abc', 'x') === false);
echo "\n";
--EXPECT--
mb_stripos: yes
mb_strrpos: yes
mb_strrichr: yes
6
7
World
true
