--TEST--
Language: abstract enum declaration and case fetch (#3737)
--FILE--
<?php
abstract enum E { case A; }
echo E::A->name;
--EXPECT--
A
