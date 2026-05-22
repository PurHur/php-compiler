<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: class with one public method (issue #514 / ClassMethod lowering).
 */

class Greeter
{
    public function greet(): string
    {
        return "hi\n";
    }
}

echo (new Greeter())->greet();
