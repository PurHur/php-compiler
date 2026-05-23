--TEST--
stdlib preg_quote()
--FILE--
<?php
echo preg_quote('hello.world'), "\n";
echo preg_quote('a+b*c?'), "\n";
echo preg_quote('[a-z]', '/'), "\n";
echo preg_quote('foo#bar', '#'), "\n";
echo preg_quote('back\slash'), "\n";
echo preg_quote('pipe|x'), "\n";
--EXPECT--
hello\.world
a\+b\*c\?
\[a\-z\]
foo\#bar
back\\slash
pipe\|x
