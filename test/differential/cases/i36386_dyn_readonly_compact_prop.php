<?php

declare(strict_types=1);

/**
 * Typed int property ++ / method return matches Zend (#36386 dyn-readonly IR compact).
 *
 * // @differential-repeat: 3
 */

final class Node
{
    public int $value;

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

$n = new Node(40);
echo $n->bump(), '|', $n->value, "\n";
