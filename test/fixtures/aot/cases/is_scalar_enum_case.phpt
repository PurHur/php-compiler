--TEST--
AOT: is_scalar() on backed and unit enum cases returns false (#5656)
--FILE--
<?php
enum E: int { case A = 1; }
echo is_scalar(E::A) ? 'y' : 'n', "\n";
enum U { case B; }
echo is_scalar(U::B) ? 'y' : 'n', "\n";
--EXPECT--
n
n
