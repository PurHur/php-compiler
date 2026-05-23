<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: typed int property assignment (self-host JIT propertyStore).
 */

class Counter
{
    public int $n = 0;

    public function bump(): int
    {
        $this->n = $this->n + 1;

        return $this->n;
    }
}

echo (string) (new Counter())->bump();
