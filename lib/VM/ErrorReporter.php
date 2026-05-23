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

    public function setErrorReporting(int $level): void
    {
        $this->errorReporting = $level;
    }

    public function setDisplayErrors(bool $display): void
    {
        $this->displayErrors = $display;
    }

    public function undefinedArrayKey(Variable $index, ?string $file = null): void
    {
        $this->emit(self::E_WARNING, "Undefined array key {$this->formatArrayKey($index)}", $file);
    }

    /**
     * trigger_error() VM path (issue #1221).
     *
     * @throws \ErrorException when E_USER_ERROR is triggered and displayed
     */
    public function triggerError(string $message, int $type = self::E_USER_NOTICE, ?string $file = null): void
    {
        if (0 === ($this->errorReporting & $type)) {
            return;
        }
        $prefix = self::prefixForType($type);
        if (null === $prefix) {
            return;
        }
        $line = $prefix . $message;
        if (null !== $file && '' !== $file) {
            $line .= " in {$file}";
        }
        $line .= "\n";
        if ($this->displayErrors) {
            fwrite(STDERR, $line);
        }
        if (self::E_USER_ERROR === $type) {
            throw new \ErrorException(rtrim($prefix . $message));
        }
    }

    private function emit(int $type, string $body, ?string $file = null): void
    {
        if (0 === ($this->errorReporting & $type)) {
            return;
        }
        $prefix = self::prefixForType($type);
        if (null === $prefix) {
            return;
        }
        $message = $prefix . $body;
        if (null !== $file && '' !== $file) {
            $message .= " in {$file}";
        }
        $message .= "\n";
        if ($this->displayErrors) {
            fwrite(STDERR, $message);
        }
    }

    private static function prefixForType(int $type): ?string
    {
        return match ($type) {
            self::E_WARNING => 'Warning: ',
            self::E_USER_ERROR => 'Fatal error: ',
            self::E_USER_WARNING => 'User warning: ',
            self::E_USER_NOTICE => 'User notice: ',
            self::E_USER_DEPRECATED => 'User deprecated: ',
            default => null,
        };
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
