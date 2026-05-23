<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: switch lowered to EQUAL + JUMPIF chain (Compiler::compileSwitchAsJumpIfChain pattern).
 */

function dispatch(int $code): string
{
    switch ($code) {
        case 1:
            return 'one';
        case 2:
            return 'two';
        default:
            return 'other';
    }
}

echo dispatch(1);
echo "\n";
echo dispatch(2);
echo "\n";
echo dispatch(99);
echo "\n";
