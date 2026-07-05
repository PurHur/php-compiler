<?php

$r = new Random\Randomizer(new Random\Engine\Mt19937(1234));
$a = [1, 2, 3, 4];
$r->shuffleArray($a);
echo 'ok' . PHP_EOL;
