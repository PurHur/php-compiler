<?php

declare(strict_types=1);

use BcMath\Number;

if (!class_exists(Number::class, false)) {
    fwrite(STDERR, "BcMath\\Number missing\n");
    exit(1);
}

$a = new Number('1.5');
$b = new Number('2.5');
echo $a->add($b)->value, "\n";
