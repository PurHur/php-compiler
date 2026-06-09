--TEST--
stdlib array_pad()/array_chunk() preserve backed enum case objects (#5553, ext/standard/array.c)
--FILE--
<?php
enum E: int
{
    case A = 1;
    case B = 2;
}
$a = [E::A, E::B];
$p = array_pad($a, 4, E::A);
foreach ($p as $v) {
    echo $v->name, ($v instanceof E ? '' : '!'), "\n";
}
$c = array_chunk($a, 1);
foreach ($c as $chunk) {
    echo $chunk[0]->name, ($chunk[0] instanceof E ? '' : '!'), "\n";
}
--EXPECT--
A
B
A
A
A
B
