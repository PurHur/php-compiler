<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmErrorHandler;

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

    /** @var list<array{0: ?string, 1: int}> */
    private array $handlerStack = [];

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

    public function pushHandler(?string $callbackName, int $mask): ?string
    {
        $previous = $this->activeHandlerName();
        $this->handlerStack[] = [$callbackName, $mask];

        return $previous;
    }

    public function popHandler(): bool
    {
        if ([] === $this->handlerStack) {
            return false;
        }
        array_pop($this->handlerStack);

        return true;
    }

    public function undefinedArrayKey(
        Variable $index,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        if (0 === ($this->errorReporting & self::E_WARNING)) {
            return;
        }
        $key = $this->formatArrayKey($index);
        $message = "Undefined array key {$key}";
        if ($this->dispatchUserHandler($context, $frame, self::E_WARNING, $message, $file, 0)) {
            return;
        }
        $line = "Warning: {$message}";
        if (null !== $file && '' !== $file) {
            $line .= " in {$file}";
        }
        $line .= "\n";
        if ($this->displayErrors) {
            fwrite(STDERR, $line);
        }
    }

    public function triggerError(
        string $message,
        int $level,
        ?string $file = null,
        ?Context $context = null,
        ?Frame $frame = null
    ): void {
        if (0 === ($this->errorReporting & $level)) {
            return;
        }
        if ($this->dispatchUserHandler($context, $frame, $level, $message, $file, 0)) {
            if (self::E_USER_ERROR === $level) {
                throw new \LogicException("Fatal error: {$message}");
            }

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

    private function activeHandlerName(): ?string
    {
        if ([] === $this->handlerStack) {
            return null;
        }

        return $this->handlerStack[\count($this->handlerStack) - 1][0];
    }

    private function dispatchUserHandler(
        ?Context $context,
        ?Frame $frame,
        int $errno,
        string $errstr,
        ?string $errfile,
        int $errline
    ): bool {
        if (null === $context || null === $frame || [] === $this->handlerStack) {
            return false;
        }
        [$callbackName, $mask] = $this->handlerStack[\count($this->handlerStack) - 1];
        if (null === $callbackName) {
            return false;
        }
        if (0 === ($mask & $errno)) {
            return false;
        }

        return VmErrorHandler::invokeHandler(
            $context,
            $frame,
            $callbackName,
            $errno,
            $errstr,
            $errfile,
            $errline
        );
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
