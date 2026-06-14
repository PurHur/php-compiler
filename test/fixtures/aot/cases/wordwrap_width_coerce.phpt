--TEST--
AOT wordwrap() — float and numeric-string width coercion (issue #4212)
--FILE--
<?php
echo wordwrap('hello', 1.9), "\n";
echo wordwrap('hello world', 3.7), "\n";
echo wordwrap('hello world', '3'), "\n";
--EXPECT--
hello
hello
world
hello
world
