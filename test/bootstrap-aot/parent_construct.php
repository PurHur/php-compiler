<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: parent::__construct() from instance constructor (JIT implicit $this).
 */

class ParentConstructBase
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

class ParentConstructChild extends ParentConstructBase
{
    public function __construct(string $name)
    {
        parent::__construct($name);
    }
}

echo (new ParentConstructChild('probe'))->name;
