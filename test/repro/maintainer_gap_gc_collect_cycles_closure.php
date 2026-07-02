<?php

declare(strict_types=1);

#[\AllowDynamicProperties]
class Node
{
}

$a = new Node();
$b = new Node();
$a->next = $b;
$b->next = $a;
unset($a, $b);

$collect = static function (): int {
    return gc_collect_cycles();
};

echo $collect(), "\n";
