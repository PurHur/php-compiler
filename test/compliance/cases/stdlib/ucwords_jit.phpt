--TEST--
stdlib ucwords() JIT
--FILE--
<?php
echo ucwords('hello world'), "\n";
echo ucwords('  hello'), "\n";
echo ucwords('hello-world'), "\n";
--EXPECT--
Hello World
  Hello
Hello-world
