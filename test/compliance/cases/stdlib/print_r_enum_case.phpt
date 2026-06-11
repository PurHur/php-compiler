--TEST--
stdlib print_r() — backed and unit enum cases show name/value (ext/standard/var.c, #5608)
--FILE--
<?php
enum E: int { case A = 1; }
enum U { case B; }
echo print_r(E::A, true);
echo "---\n";
echo print_r(U::B, true);
--EXPECT--
E Enum:int
(
    [name] => A
    [value] => 1
)
---
U Enum
(
    [name] => B
)
