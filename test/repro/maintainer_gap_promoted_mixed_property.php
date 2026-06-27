<?php

declare(strict_types=1);

class Box
{
    public function __construct(
        public mixed $value,
    ) {
    }
}

$b = new Box(42);
echo 'ok' . PHP_EOL;
echo $b->value . PHP_EOL;
