--TEST--
stdlib ucfirst()
--FILE--
<?php
echo ucfirst(''), "\n";
echo ucfirst('hello'), "\n";
echo ucfirst('world'), "\n";
echo ucfirst('123'), "\n";
--EXPECT--

Hello
World
123
