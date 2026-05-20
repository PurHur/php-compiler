--TEST--
stdlib ucwords()
--FILE--
<?php
echo ucwords(''), "\n";
echo ucwords('hello world'), "\n";
echo ucwords("hello\tworld"), "\n";
echo ucwords('2nd story'), "\n";
echo ucwords('123'), "\n";
--EXPECT--


Hello World
Hello	World
2nd Story
123
