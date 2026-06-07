--TEST--
AOT: is_numeric() on backed and unit enum cases returns false (#7008)
--FILE--
<?php
enum E: int { case A = 1; }
echo is_numeric(E::A) ? 'y' : 'n', "\n";
enum S: string { case A = '1'; }
echo is_numeric(S::A) ? 'y' : 'n', "\n";
enum U { case B; }
echo is_numeric(U::B) ? 'y' : 'n', "\n";
--EXPECT--
n
n
n
