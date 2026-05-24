<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend-style warnings for compiled VM code (issue #273).
 */
final class ErrorReporter
{
    public const E_WARNING = 2;
    public const E_USER_ERROR = 256;
    public const E_USER_WARNING = 512;
    public const E_USER_NOTICE = 1024;
    public const E_USER_DEPRECATED = 16384;

    private int $errorReporting;
    private bool $displayErrors;

    public function __construct(
        int $errorReporting = E_ALL,
        bool $displayErrors = true
    ) {
        $this->errorReporting = $errorReporting;
        $this->displayErrors = $displayErrors;
    }

    public function getErrorReporting(): int
    {
        return $this->errorReporting;
    }

    public function setErrorReporting(int $level): void
    {
        $this->errorReporting = $level;
    }

    public function getDisplayErrors(): bool
    {
        return $this->displayErrors;
    }

    public function setDisplayErrors(bool $display): void
    {
        $this->displayErrors = $display;
    }

    public function undefinedArrayKey(Variable $index, ?string $file = null): void
    {
        if (0 === ($this->errorReporting & self::E_WARNING)) {
            return;
        }
        $key = $this->formatArrayKey($index);
        $message = "Warning: Undefined array key {$key}";
        if (null !== $file && '' !== $file) {
            $message .= " in {$file}";
        }
        $message .= "\n";
        if ($this->displayErrors) {
            fwrite(STDERR, $message);
        }
    }

    public function triggerError(string $message, int $level, ?string $file = null): void
    {
        if (0 === ($this->errorReporting & $level)) {
            return;
        }
        $prefix = match ($level) {
            self::E_USER_ERROR => 'Fatal error',
            self::E_USER_WARNING => 'Warning',
            self::E_USER_NOTICE => 'Notice',
            self::E_USER_DEPRECATED => 'Deprecated',
            default => 'Unknown error',
        };
        $line = "{$prefix}: {$message}";
        if (null !== $file && '' !== $file) {
            $line .= " in {$file}";
        }
        $line .= "\n";
        if ($this->displayErrors) {
            fwrite(STDERR, $line);
        }
        if (self::E_USER_ERROR === $level) {
            throw new \LogicException(rtrim($line));
        }
    }

    private function formatArrayKey(Variable $index): string
    {
        if (Variable::TYPE_STRING === $index->type) {
            return '"' . $index->toString() . '"';
        }
        if (Variable::TYPE_INTEGER === $index->type) {
            return (string) $index->toInt();
        }

        return '"' . $index->toString() . '"';
    }
}
