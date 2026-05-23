--TEST--
language: echo object without __toString (issue #71, match VM)
--FILE--
<?php
class C {}
echo new C(), "\n";
--EXPECT--
Object
