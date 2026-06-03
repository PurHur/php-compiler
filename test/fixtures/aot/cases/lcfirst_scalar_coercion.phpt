--TEST--
AOT lcfirst() scalar coercion (#4729)
--FILE--
<?php
echo lcfirst('Hello'), "\n";
echo lcfirst('123abc'), "\n";
echo lcfirst(42), "\n";
--EXPECT--
hello
123abc
42
