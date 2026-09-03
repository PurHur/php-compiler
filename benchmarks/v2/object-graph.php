<?php

declare(strict_types=1);

/**
 * Object graph — property/method call volume (#36385).
 */

final class Node
{
    public int $value;
    public ?Node $next = null;

    public function __construct(int $value)
    {
        $this->value = $value;
    }

    public function bump(): int
    {
        ++$this->value;

        return $this->value;
    }
}

$n = 20000;
$head = new Node(0);
$cur = $head;
for ($i = 1; $i < $n; ++$i) {
    $cur->next = new Node($i);
    $cur = $cur->next;
}

$sum = 0;
$p = $head;
while (null !== $p) {
    $sum += $p->bump();
    $p = $p->next;
}

echo $sum, "\n";
