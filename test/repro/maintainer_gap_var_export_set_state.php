<?php

declare(strict_types=1);

class VE
{
    public static function __set_state(array $a): self
    {
        return new self();
    }
}

echo var_export(VE::__set_state([]), true);
echo "\n";
