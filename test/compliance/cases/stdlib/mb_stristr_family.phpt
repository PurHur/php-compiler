--TEST--
stdlib mb_stristr()/mb_strrchr()/mb_strripos() multibyte search (VM, #20006)
--FILE--
<?php
foreach (['mb_stristr', 'mb_strrchr', 'mb_strripos'] as $fn) {
    echo $fn, ': ', function_exists($fn) ? 'yes' : 'no', "\n";
}
echo mb_stristr('Hello World', 'WORLD'), "\n";
echo mb_strrchr('Hello World', 'o'), "\n";
echo mb_strripos('Hello World', 'L'), "\n";
echo mb_stristr('Hello World', 'WORLD', true), "\n";
echo mb_strrchr('Hello World', 'o', true), "\n";
var_export(mb_stristr('abc', 'x') === false);
echo "\n";
--EXPECT--
mb_stristr: yes
mb_strrchr: yes
mb_strripos: yes
World
orld
9
Hello 
Hello W
true
