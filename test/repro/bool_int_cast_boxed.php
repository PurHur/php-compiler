<?php

declare(strict_types=1);

/** Boxed bool (int) cast must read writeBool payload, not __value__readLong (#1056). */

$x = 1 === 1;
echo (int) $x, "\n";

$y = true;
echo (int) $y, "\n";

class Box
{
}

$a = new Box();
$c = $a;
$same = $a === $c;
echo (int) $same, "\n";
