--TEST--
AOT: ucwords() optional separators
--FILE--
<?php
echo ucwords('hello-world', '-'), "\n";
echo ucwords('hello.world', '.'), "\n";
echo ucwords('hello world'), "\n";
--EXPECT--
Hello-World
Hello.World
Hello World
