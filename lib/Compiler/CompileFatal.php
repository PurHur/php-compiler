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
     * True when the diagnostic is a Zend parser syntax error (Parse error channel).
     *
     * Reference-profile rejectors and php-parser-shaped messages use this prefix on 8.2
     * (#18019, #18085, Zend/zend_language_parser.y) — not compile-time fatals.
     */
    public static function isSyntaxParseErrorMessage(string $message): bool
    {
        return (bool) preg_match('/^syntax error\b/i', trim($message));
    }

    /**
     * Zend-shaped compile diagnostic for CLI stderr (#6835, bin/vm.php).
     */
    public static function formatZendStderrLine(string $message, string $sourceFile, int $sourceLine): string
    {
        $prefix = self::isSyntaxParseErrorMessage($message) ? 'PHP Parse error' : 'PHP Fatal error';

        return sprintf("%s:  %s in %s on line %d\n", $prefix, $message, $sourceFile, max(1, $sourceLine));
    }

    public function zendStderrLine(): string
    {
        return self::formatZendStderrLine($this->getMessage(), $this->sourceFile, $this->sourceLine);
    }
}
