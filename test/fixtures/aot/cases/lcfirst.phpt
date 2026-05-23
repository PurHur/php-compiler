--TEST--
AOT: lcfirst() ASCII first character
--FILE--
<?php
echo lcfirst(''), "\n";
echo lcfirst('Hello'), "\n";
echo lcfirst('WORLD'), "\n";
echo lcfirst('123'), "\n";
--EXPECT--

hello
wORLD
123
