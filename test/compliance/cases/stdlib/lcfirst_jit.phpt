--TEST--
stdlib lcfirst() JIT
--FILE--
<?php
echo lcfirst('Hello'), "\n";
--EXPECT--
hello
