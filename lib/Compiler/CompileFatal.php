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

    /**
     * Zend-shaped compile fatal for CLI stderr (#6835, bin/vm.php).
     */
    public static function formatZendStderrLine(string $message, string $sourceFile, int $sourceLine): string
    {
        return sprintf("Fatal error: %s in %s on line %d\n", $message, $sourceFile, max(1, $sourceLine));
    }

    public function zendStderrLine(): string
    {
        return self::formatZendStderrLine($this->getMessage(), $this->sourceFile, $this->sourceLine);
    }
}
