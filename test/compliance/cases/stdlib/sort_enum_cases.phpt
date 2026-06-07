--TEST--
stdlib sort() on enum case arrays preserves objects (#5546, ext/standard/array.c)
--FILE--
<?php
enum EInt: int { case A = 1; case B = 2; case C = 3; }
enum EUnit { case A; case B; }

$a = [EInt::C, EInt::A, EInt::B];
sort($a);
foreach ($a as $v) {
    echo $v->name, ($v instanceof EInt ? '' : '!'), "\n";
}

$b = [EUnit::B, EUnit::A];
sort($b);
foreach ($b as $v) {
    echo $v->name, ($v instanceof EUnit ? '' : '!'), "\n";
}
--EXPECT--
A
B
C
A
B
