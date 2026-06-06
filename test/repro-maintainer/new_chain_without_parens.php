<?php

declare(strict_types=1);

/**
 * Issue #6974 — dereferencable `new` without outer parentheses (PHP 8.4).
 */

class Greeter
{
    public function greet(): string
    {
        return 'hello';
    }
}

echo new Greeter()->greet(), "\n";
