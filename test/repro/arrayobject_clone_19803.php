<?php
$a = new ArrayObject([1, 2]);
$b = clone $a;
echo $b->count(), "\n";
$b[] = 3;
echo $a->count(), ":", $b->count(), "\n";
