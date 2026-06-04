<?php

declare(strict_types=1);

/** Issue #5310: ?-> on nullable typed property in ?? read context (lib/JIT.php pattern). */
class T
{
    public ?object $type = null;
}

class B
{
    public function getOperand(int $i): object
    {
        return new T();
    }
}

function probe(B $block, int $argOffset): void
{
    $classHint = $block->getOperand($argOffset)->type?->userType ?? null;
}
