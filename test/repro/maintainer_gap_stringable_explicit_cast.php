<?php

declare(strict_types=1);

class C implements Stringable
{
    public function __toString(): string
    {
        return 'x';
    }
}

var_export((string) new C());
echo "\n";
var_export(strval(new C()));
echo "\n";
