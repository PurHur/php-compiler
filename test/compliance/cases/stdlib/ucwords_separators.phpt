--TEST--
stdlib ucwords() optional separators
--FILE--
<?php
echo ucwords('hello-world', '-'), "\n";
echo ucwords('hello.world', '.'), "\n";
echo ucwords('hello world'), "\n";
echo ucwords('a|b|c', '|'), "\n";
--EXPECT--
Hello-World
Hello.World
Hello World
A|B|C
