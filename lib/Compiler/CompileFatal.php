<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

/**
 * Compile-time fatal with source location (#3548, lint integration).
 */
final class CompileFatal extends \CompileError
{
    public function __construct(
        public readonly string $sourceFile,
        public readonly int $sourceLine,
        string $message
    ) {
        parent::__construct($message);
    }
}
