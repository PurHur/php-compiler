<?php

declare(strict_types=1);

class MyDate extends DateTime
{
}

$d = new MyDate('2020-01-01');
$c = DateTime::createFromInterface($d);
echo $c->format('Y-m-d'), "\n";
