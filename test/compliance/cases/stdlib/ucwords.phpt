--TEST--
stdlib ucwords()
--FILE--
<?php
echo ucwords(''), "\n";
echo ucwords('hello world'), "\n";
echo ucwords('  hello'), "\n";
echo ucwords('hello-world'), "\n";
echo ucwords("foo\nbar"), "\n";
echo ucwords('123 abc'), "\n";
--EXPECT--

Hello World
  Hello
Hello-world
Foo
Bar
123 Abc
