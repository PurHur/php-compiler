--TEST--
AOT ucfirst() scalar coercion (#4729)
--FILE--
<?php
echo ucfirst('hello'), "\n";
echo ucfirst('123abc'), "\n";
echo ucfirst(42), "\n";
--EXPECT--
Hello
123abc
42
