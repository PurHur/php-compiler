<?php

use BcMath\Number;

if (!class_exists(Number::class, false)) {
    fwrite(STDERR, "BcMath\\Number missing\n");
    exit(1);
}
$a = new Number('1.234');
$b = new Number('5');
echo $a->add($b, 2)->value, "\n";
