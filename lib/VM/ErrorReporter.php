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

    /** Nesting depth for `@` error-control (issue #3546). */
    private int $silenceDepth = 0;

    private int $savedErrorReporting = 0;

    /** @var list<array{0: ?string, 1: int}> */
    private array $handlerStack = [];

    /** @var array{type: int, message: string, file: string, line: int}|null */
    private ?array $lastError = null;

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

    public function beginSilence(): void
    {
        if (0 === $this->silenceDepth) {
            $this->savedErrorReporting = $this->errorReporting;
            $this->errorReporting = 0;
        }
        ++$this->silenceDepth;
    }

    public function endSilence(): void
    {
        if ($this->silenceDepth <= 0) {
            return;
        }
        --$this->silenceDepth;
        if (0 === $this->silenceDepth) {
            $this->errorReporting = $this->savedErrorReporting;
        }
    }

    public function isSilenced(): bool
    {
        return $this->silenceDepth > 0;
    }

    /**
     * @return array{type: int, message: string, file: string, line: int}|null
     */
    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    public function clearLastError(): void
    {
        $this->lastError = null;
    }

    public function recordLastError(int $type, string $message, ?string $file, int $line): void
    {
        $this->lastError = [
            'type' => $type,
            'message' => $message,
            'file' => null !== $file ? $file : '',
            'line' => $line,
        ];
    }

    public function getLastErrorVariable(): Variable
    {
        $out = new Variable();
        if (null === $this->lastError) {
            $out->null();

            return $out;
        }
        $ht = new HashTable();
        $typeVar = new Variable(Variable::TYPE_INTEGER);
        $typeVar->int($this->lastError['type']);
        $ht->add('type', $typeVar);
        $messageVar = new Variable(Variable::TYPE_STRING);
        $messageVar->string($this->lastError['message']);
        $ht->add('message', $messageVar);
        $fileVar = new Variable(Variable::TYPE_STRING);
        $fileVar->string($this->lastError['file']);
        $ht->add('file', $fileVar);
        $lineVar = new Variable(Variable::TYPE_INTEGER);
        $lineVar->int($this->lastError['line']);
        $ht->add('line', $lineVar);
        $out->array($ht);

        return $out;
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
        $this->recordLastError(self::E_WARNING, $message, $file, 0);
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
        $this->recordLastError($level, $message, $file, 0);
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
