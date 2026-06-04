<?php

declare(strict_types=1);

namespace PHPCfg\Op\Stmt;

use PHPCfg\Op\Stmt;

class HaltCompiler extends Stmt
{
    /** @var string bytes after __halt_compiler(); in source (PHAR stubs, issue #3479) */
    public string $remaining;

    /** Byte offset of trailing data (end of `__halt_compiler();`, issue #5455). */
    public int $haltOffset;

    public function __construct(string $remaining, int $haltOffset, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->remaining = $remaining;
        $this->haltOffset = $haltOffset;
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
