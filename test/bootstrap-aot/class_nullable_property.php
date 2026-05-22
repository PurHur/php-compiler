<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: nullable typed property with null default (#514).
 */

class Holder
{
    public ?string $name = null;

    public function label(): string
    {
        return null === $this->name ? 'anon' : $this->name;
    }
}

echo (new Holder())->label();
