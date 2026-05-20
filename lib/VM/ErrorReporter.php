<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend-style warnings for compiled VM code (issue #273).
 */
final class ErrorReporter
{
    public const E_WARNING = 2;

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
