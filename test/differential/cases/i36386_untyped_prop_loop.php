<?php

declare(strict_types=1);

// Untyped property assign/inc must match Zend through 1M iterations (#36386).
class C
{
    public $x = 0;
}

$o = new C();
for ($i = 0; $i < 100000; ++$i) {
    $o->x = $o->x + 1;
}
$o2 = new C();
for ($i = 0; $i < 100000; ++$i) {
    $o2->x++;
}
echo $o->x, '|', $o2->x, "\n";
