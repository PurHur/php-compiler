--TEST--
stdlib ucwords() separators JIT
--FILE--
<?php
echo ucwords('hello-world', '-'), "\n";
echo ucwords('hello.world', '.'), "\n";
--EXPECT--
Hello-World
Hello.World
