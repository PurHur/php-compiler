<?php
declare(strict_types=1);

$n = 0;
foreach (new DateTime('2020-01-01 UTC') as $k => $v) {
    echo "$k\n";
    $n++;
}
echo "count=$n\n";

$n = 0;
foreach (new DateTimeImmutable('2020-01-01 UTC') as $k => $v) {
    echo "I:$k\n";
    $n++;
}
echo "icount=$n\n";

class MyDT23432 extends DateTime
{
    public $x = 1;
}

$n = 0;
foreach (new MyDT23432('2020-01-01 UTC') as $k => $v) {
    echo "S:$k\n";
    $n++;
}
echo "scount=$n\n";
