--TEST--
Language: enum __debugInfo — JIT compile-time fatal (#5886)
--FILE--
<?php
enum E: int {
    case A = 1;
    public function __debugInfo(): array {
        return ['x' => 1];
    }
}
var_export(E::A);
echo "\n";
--EXPECT_EXIT--
255
