<?php

declare(strict_types=1);

namespace Pkg;

final class Hello
{
    public function greet(string $name): string
    {
        return 'hello '.$name;
    }
}
