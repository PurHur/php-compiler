--TEST--
Language: int backed enum case with explicit value compiles and runs (#5491)
--FILE--
<?php
enum E: int {
    case A = 1;
}
echo E::A->value, "\n";
--EXPECT--
1
