--TEST--
stdlib sort() on enum case arrays preserves objects (#5546, #5691, ext/standard/array.c)
--FILE--
<?php
enum EInt: int { case A = 1; case B = 2; case C = 3; }
enum EUnit { case A; case B; }
enum EStr: string { case A = 'b'; case B = 'a'; }
enum EOrder: int { case C = 3; case A = 1; case B = 2; }

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

$c = [EStr::B, EStr::A];
sort($c);
foreach ($c as $v) {
    echo $v->name, ($v instanceof EStr ? '' : '!'), "\n";
}

$d = [EOrder::B, EOrder::C, EOrder::A];
sort($d);
foreach ($d as $v) {
    echo $v->name, ($v instanceof EOrder ? '' : '!'), "\n";
}
--EXPECT--
A
B
C
A
B
A
B
A
B
C
