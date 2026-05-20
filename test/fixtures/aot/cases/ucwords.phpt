--TEST--
AOT: ucwords() default whitespace mask
--FILE--
<?php
echo ucwords('hello world'), "\n";
echo ucwords('  hello'), "\n";
echo ucwords('hello-world'), "\n";
--EXPECT--
Hello World
  Hello
Hello-world
