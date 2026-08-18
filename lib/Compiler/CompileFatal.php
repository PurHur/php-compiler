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

    /**
     * Zend text for php-parser {@code checkUseUse()} when the alias is {@code self} or {@code parent}.
     *
     * {@code use Foo as static} is a Zend parse error (unexpected token), not this compile fatal —
     * do not remap {@code 'static'} (#32254, Zend/zend_compile.c zend_compile_use()).
     */
    public static function useAsSpecialClassNameMessage(string $parserMessage): ?string
    {
        $message = trim($parserMessage);
        if (1 === preg_match('/^(.*) on (?:unknown line|line \\d+)$/', $message, $m)) {
            $message = trim($m[1]);
        }
        if (1 !== preg_match(
            "/^Cannot use .+ as .+ because '(self|parent)' is a special class name$/i",
            $message
        )) {
            return null;
        }

        return $message;
    }

    /**
     * Map php-parser checkUseUse() Error to Zend compile fatal (#32254).
     *
     * @return never
     */
    public static function rethrowUseAsSpecialClassName(\Throwable $e, string $filename): never
    {
        $raw = $e instanceof \PhpParser\Error ? $e->getRawMessage() : $e->getMessage();
        $mapped = self::useAsSpecialClassNameMessage($raw);
        if (null === $mapped) {
            throw $e;
        }
        $line = 1;
        if ($e instanceof \PhpParser\Error && $e->getStartLine() > 0) {
            $line = $e->getStartLine();
        } elseif (preg_match('/\\bon line (\\d+)\\b/', $e->getMessage(), $m)) {
            $line = max(1, (int) $m[1]);
        }
        throw new self('' !== $filename ? $filename : 'unknown', $line, $mapped);
    }
}
