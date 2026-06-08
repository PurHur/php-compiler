<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;

/** Disallow user instantiation of Resource (#7071). */
final class ResourceConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('Cannot directly instantiate Resource');
    }
}
