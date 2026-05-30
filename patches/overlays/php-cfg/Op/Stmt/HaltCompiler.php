<?php

declare(strict_types=1);

namespace PHPCfg\Op\Stmt;

use PHPCfg\Op\Stmt;

class HaltCompiler extends Stmt
{
    /** @var string bytes after __halt_compiler(); in source (PHAR stubs, issue #3479) */
    public string $remaining;

    public function __construct(string $remaining, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->remaining = $remaining;
    }

    public function getSubBlocks(): array
    {
        return [];
    }

    public function getType(): string
    {
        return 'Stmt_HaltCompiler';
    }
}
