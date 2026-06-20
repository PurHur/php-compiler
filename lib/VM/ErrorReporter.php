<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\NativeLastError;
use PHPCompiler\ext\standard\VmErrorHandler;

/**
 * Zend-style warnings for compiled VM code (issue #273).
 */
final class ErrorReporter
{
    public const E_PARSE = 4;
    public const E_WARNING = 2;
    public const E_NOTICE = 8;
    public const E_USER_ERROR = 256;
    public const E_USER_WARNING = 512;
    public const E_USER_NOTICE = 1024;
    public const E_USER_DEPRECATED = 16384;
    public const E_DEPRECATED = 8192;

    /**
     * Zend startup mask: E_ALL & ~E_DEPRECATED & ~E_STRICT (main/main.c; issue #4842).
     *
     * E_STRICT is 2048 on PHP ≤8.3; bit cleared even when the constant is removed (PHP 8.4+).
     */
    public const DEFAULT_STARTUP_REPORTING = \E_ALL & ~self::E_DEPRECATED & ~2048;

    /** Valid trigger_error() $error_level values (ext/standard/basic_functions.c). */
    public static function isUserErrorLevel(int $level): bool
    {
        return \in_array($level, [
            self::E_USER_ERROR,
            self::E_USER_WARNING,
            self::E_USER_NOTICE,
            self::E_USER_DEPRECATED,
        ], true);
    }

    private int $errorReporting;
    private bool $displayErrors;

    /** Nesting depth for `@` error-control (issue #3546). */
    private int $silenceDepth = 0;

    private int $savedErrorReporting = 0;

    /** @var list<array{0: Variable, 1: int}> */
    private array $handlerStack = [];

    public function __construct(
        int $errorReporting = self::DEFAULT_STARTUP_REPORTING,
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

    public function clearLastError(): void
    {
        NativeLastError::clear();
    }

    public function recordLastError(int $type, string $message, ?string $file, int $line): void
    {
        NativeLastError::record($type, $message, $file, $line);
    }

    public function getLastErrorVariable(): Variable
    {
        return NativeLastError::getLastErrorVariable();
    }

    public function pushHandler(Variable $callback, int $mask): ?Variable
    {
        $previous = $this->activeHandlerCopy();
        $stored = new Variable();
        $stored->copyFrom($callback->resolveIndirect());
        $this->handlerStack[] = [$stored, $mask];

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

    public function stringOffsetCastOccurred(
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitWarning(
            'String offset cast occurred',
            $context,
            $frame,
            $file
        );
    }

    public function uninitializedStringOffset(
        int $offset,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitWarning(
            "Uninitialized string offset {$offset}",
            $context,
            $frame,
            $file
        );
    }

    public function illegalStringOffset(
        int $offset,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitWarning(
            "Illegal string offset {$offset}",
            $context,
            $frame,
            $file
        );
    }

    public function undefinedVariable(
        string $name,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitWarning("Undefined variable \${$name}", $context, $frame, $file);
    }

    public function undefinedArrayKey(
        Variable $index,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $key = $this->formatArrayKey(HashTable::normalizeIndexKey($index));
        $message = "Undefined array key {$key}";
        $this->emitWarning($message, $context, $frame, $file);
    }

    /**
     * Zend E_WARNING text for ZEND_FETCH_DIM_R on scalars (zend_execute.c, #4867).
     */
    public static function arrayOffsetOnNonContainerMessage(string $typeName): string
    {
        return "Trying to access array offset on value of type {$typeName}";
    }

    /**
     * Zend E_WARNING for ZEND_FETCH_DIM_R on scalars (zend_execute.c, #4867).
     */
    public function arrayOffsetOnNonContainer(
        string $typeName,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitWarning(
            self::arrayOffsetOnNonContainerMessage($typeName),
            $context,
            $frame,
            $file
        );
    }

    /**
     * Zend E_WARNING for property read on non-object including null (zend_fetch.c, #5276, #10381).
     */
    public function propertyReadOnNonObject(
        string $propertyName,
        string $typeName,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitWarning(
            sprintf('Attempt to read property "%s" on %s', $propertyName, $typeName),
            $context,
            $frame,
            $file
        );
    }

    /**
     * Zend E_WARNING for language-level diagnostics (issue #4502).
     */
    public function languageWarning(
        string $message,
        ?string $file,
        int $line,
        ?Context $context = null,
        ?Frame $frame = null
    ): void {
        $this->emitWarning($message, $context, $frame, $file, $line);
    }

    /**
     * Zend E_NOTICE for nested write through ArrayAccess offsetGet (zend_object_handlers.c, #5460).
     */
    public function indirectModificationOfOverloadedElement(
        string $className,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitNotice(
            sprintf('Indirect modification of overloaded element of %s has no effect', $className),
            $context,
            $frame,
            $file
        );
    }

    private function emitNotice(
        string $message,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null,
        int $line = 0
    ): void {
        [$file, $line] = $this->resolveDisplayLocation($frame, $file, $line);
        if (0 !== ($this->errorReporting & self::E_NOTICE)
            && $this->dispatchUserHandler($context, $frame, self::E_NOTICE, $message, $file, $line)) {
            return;
        }
        $this->recordLastError(self::E_NOTICE, $message, $file, $line);
        if (0 === ($this->errorReporting & self::E_NOTICE)) {
            return;
        }
        if ($this->displayErrors) {
            fwrite(STDERR, $this->formatCliError(self::E_NOTICE, $message, $file, $line));
        }
    }

    private function emitWarning(
        string $message,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null,
        int $line = 0
    ): void {
        [$file, $line] = $this->resolveDisplayLocation($frame, $file, $line);
        if (0 !== ($this->errorReporting & self::E_WARNING)
            && $this->dispatchUserHandler($context, $frame, self::E_WARNING, $message, $file, $line)) {
            return;
        }
        $this->recordLastError(self::E_WARNING, $message, $file, $line);
        if (0 === ($this->errorReporting & self::E_WARNING)) {
            return;
        }
        if ($this->displayErrors) {
            fwrite(STDERR, $this->formatCliError(self::E_WARNING, $message, $file, $line));
        }
    }

    public function deprecatedDynamicProperty(
        string $className,
        string $propertyName,
        ?string $file = null,
        ?Context $context = null,
        ?Frame $frame = null
    ): void {
        $message = sprintf(
            'Creation of dynamic property %s::$%s is deprecated',
            $className,
            $propertyName
        );
        if (0 !== ($this->errorReporting & self::E_DEPRECATED)
            && $this->dispatchUserHandler($context, $frame, self::E_DEPRECATED, $message, $file, 0)) {
            return;
        }
        $this->recordLastError(self::E_DEPRECATED, $message, $file, 0);
        if (0 === ($this->errorReporting & self::E_DEPRECATED)) {
            return;
        }
        if ($this->displayErrors) {
            fwrite(STDERR, $this->formatCliError(self::E_DEPRECATED, $message, $file, 0));
        }
    }

    public function triggerError(
        string $message,
        int $level,
        ?string $file = null,
        ?Context $context = null,
        ?Frame $frame = null,
        int $line = 0
    ): void {
        [$file, $line] = $this->resolveDisplayLocation($frame, $file, $line);
        if (0 !== ($this->errorReporting & $level)
            && $this->dispatchUserHandler($context, $frame, $level, $message, $file, $line)) {
            if (self::E_USER_ERROR === $level) {
                throw new \LogicException("Fatal error: {$message}");
            }

            return;
        }
        $this->recordLastError($level, $message, $file, $line);
        if (0 === ($this->errorReporting & $level)) {
            return;
        }
        $formatted = $this->formatCliError($level, $message, $file, $line);
        if ($this->displayErrors) {
            fwrite(STDERR, $formatted);
        }
        if (self::E_USER_ERROR === $level) {
            throw new \LogicException(rtrim($formatted));
        }
    }

    /**
     * Zend FE_RESET_R foreach invalid operand: user handler runs even when error_reporting(0) (#4879).
     */
    public function triggerErrorWithHandlerFirst(
        string $message,
        int $level,
        ?string $file = null,
        ?Context $context = null,
        ?Frame $frame = null,
        int $line = 0
    ): void {
        [$file, $line] = $this->resolveDisplayLocation($frame, $file, $line);
        $this->recordLastError($level, $message, $file, $line);
        if ($this->dispatchUserHandler($context, $frame, $level, $message, $file, $line)) {
            return;
        }
        if (0 === ($this->errorReporting & $level)) {
            return;
        }
        $formatted = $this->formatCliError($level, $message, $file, $line);
        if ($this->displayErrors) {
            fwrite(STDERR, $formatted);
        }
    }

    /**
     * Zend CLI stderr line (main/main.c php_error_cb).
     */
    private function formatCliError(int $level, string $message, ?string $file, int $line): string
    {
        $prefix = match ($level) {
            self::E_WARNING, self::E_USER_WARNING => 'PHP Warning',
            self::E_NOTICE, self::E_USER_NOTICE => 'PHP Notice',
            self::E_DEPRECATED, self::E_USER_DEPRECATED => 'PHP Deprecated',
            self::E_USER_ERROR => 'PHP Fatal error',
            default => 'PHP Unknown error',
        };
        $formatted = "{$prefix}:  {$message}";
        if (null !== $file && '' !== $file) {
            $formatted .= " in {$file}";
            if ($line > 0) {
                $formatted .= " on line {$line}";
            }
        }

        return $formatted."\n";
    }

    /**
     * @return array{0: ?string, 1: int}
     */
    private function resolveDisplayLocation(?Frame $frame, ?string $file, int $line): array
    {
        if (null !== $frame) {
            if ((null === $file || '' === $file) && '' !== $frame->scriptPath) {
                $file = $frame->scriptPath;
            }
            if ($line <= 0 && $frame->callSiteLine > 0) {
                $line = $frame->callSiteLine;
            }
        }
        if (null !== $file && $this->isVirtualScriptFilename($file)) {
            $file = null;
            $line = 0;
        }

        return [$file, $line];
    }

    private function isVirtualScriptFilename(string $file): bool
    {
        return '' === $file
            || '-' === $file
            || 'Standard input code' === $file
            || 'Command line code' === $file;
    }

    private function activeHandlerCopy(): ?Variable
    {
        if ([] === $this->handlerStack) {
            return null;
        }
        $out = new Variable();
        $out->copyFrom($this->handlerStack[\count($this->handlerStack) - 1][0]);

        return $out;
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
        [$callback, $mask] = $this->handlerStack[\count($this->handlerStack) - 1];
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $callback->type) {
            return false;
        }
        if (0 === ($mask & $errno)) {
            return false;
        }

        return VmErrorHandler::invokeHandler(
            $context,
            $frame,
            $callback,
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
