--TEST--
call argument spread maps string keys to parameter names (zend_compile.c ARG_UNPACK, #4669)
--FILE--
<?php
function pair(int $a, int $b): void {
    echo "$a,$b\n";
}
function ordered(int $a, int $b = 0): void {
    echo "$a,$b\n";
}
function optional(string $a = 'd'): void {
    echo "$a\n";
}
pair(...['a' => 1, 'b' => 2]);
ordered(...['b' => 5, 'a' => 1]);
optional(...['a' => 'named']);
--EXPECT--
1,2
1,5
named
