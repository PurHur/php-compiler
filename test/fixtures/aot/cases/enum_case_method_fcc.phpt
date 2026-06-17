--TEST--
Language: enum case instance method first-class callable E::A->f(...) (AOT, #6845, #9250)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
    public function f(): int { return 42; }
}

$c = E::A->f(...);
echo $c(), "\n";
--EXPECT--
42
