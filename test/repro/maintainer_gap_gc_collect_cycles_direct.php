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

echo gc_collect_cycles(), "\n";
