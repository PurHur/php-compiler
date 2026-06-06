--TEST--
Language: list destructuring preserves enum case objects (#5766, zend_execute.c)
--FILE--
<?php
enum E: int { case A = 1; }
[$x] = [E::A];
var_export($x);
var_export($x === E::A);
--EXPECT--
\E::Atrue
