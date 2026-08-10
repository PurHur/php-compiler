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
    /** Zend E_ERROR (main/main.c; e.g. __debuginfo() must return an array, #25748). */
    public const E_ERROR = 1;
    /** Zend E_COMPILE_ERROR (main/main.c; inheritance fatals during eval, #22922). */
    public const E_COMPILE_ERROR = 64;
    public const E_USER_ERROR = 256;
    public const E_USER_WARNING = 512;
    public const E_USER_NOTICE = 1024;
    public const E_USER_DEPRECATED = 16384;
    public const E_DEPRECATED = 8192;
    /** Zend E_STRICT bit (still defined for BC on 8.4+; not included in E_ALL since 8.4). */
    public const E_STRICT = 2048;
    /**
     * Zend ≤8.3 E_ALL (includes E_STRICT) — php-src Zend/zend_constants.c before PHP 8.4.
     * Hardcoded so PROFILE gating is independent of host PHP's E_ALL (#27824).
     */
    public const E_ALL_LEGACY = 32767;
    /**
     * Zend 8.4+ E_ALL without E_STRICT — php-src Zend/zend_constants.c / migration84.
     */
    public const E_ALL_WITHOUT_STRICT = 30719;

    /**
     * Unset-profile / compliance startup mask: E_ALL & ~E_DEPRECATED & ~E_STRICT (#4842, #2055).
     *
     * Prefer {@see defaultStartupReporting()} — explicit {@code PHP_COMPILER_PROFILE} uses Zend
     * {@see eAll()} (includes E_DEPRECATED) so PROFILE=8.2+ matches php.ini (#29195, #26083).
     */
    public const DEFAULT_STARTUP_REPORTING = self::E_ALL_LEGACY & ~self::E_DEPRECATED & ~self::E_STRICT;

    /**
     * Profile-aware guest E_ALL constant value (#27824).
     *
     * php-src: Zend/zend_constants.c — E_ALL drops E_STRICT in 8.4 (32767 → 30719).
     * Gate: {@see \PHPCompiler\CompilerVersion::supportsImplicitNullableParameterDeprecation()}
     * (languageProfileVersion ≥ 8.4.0). Host PHP's {@code \E_ALL} is not used — a Zend 8.2
     * host would otherwise keep advertising 32767 under PROFILE=8.4.
     */
    public static function eAll(): int
    {
        if (\PHPCompiler\CompilerVersion::supportsImplicitNullableParameterDeprecation()) {
            return self::E_ALL_WITHOUT_STRICT;
        }

        return self::E_ALL_LEGACY;
    }

    /**
     * Profile-aware guest default for error_reporting / ErrorReporter (#4842, #26083, #27824, #29195).
     *
     * PHP 8.0+ php.ini default is E_ALL (includes E_DEPRECATED). Explicit
     * {@code PHP_COMPILER_PROFILE} uses {@see eAll()} so PROFILE=8.2/8.3 are not silent on
     * dynamic-property E_DEPRECATED (zend_object_handlers.c). Unset reference harness keeps
     * #4842's 22527 mask so compliance host {@code -d error_reporting=0} stays quiet (#2055).
     */
    public static function defaultStartupReporting(): int
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (\is_string($raw) && '' !== trim($raw)) {
            return self::eAll();
        }
        // Stable 8.4.0+ VERSION (no PROFILE): Zend E_ALL without E_STRICT (#26083).
        if (\PHPCompiler\CompilerVersion::supportsImplicitNullableParameterDeprecation()) {
            return self::eAll();
        }

        return self::DEFAULT_STARTUP_REPORTING;
    }

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
        ?int $errorReporting = null,
        bool $displayErrors = false
    ) {
        $this->errorReporting = $errorReporting ?? self::defaultStartupReporting();
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
            return true;
        }
        array_pop($this->handlerStack);

        return true;
    }

    public function getActiveHandler(): ?Variable
    {
        return $this->activeHandlerCopy();
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

    /**
     * Zend zend_check_string_offset — leading-numeric string with trailing junk (#22895).
     *
     * php-src: Zend/zend_execute.c — E_WARNING "Illegal string offset \"%s\""
     */
    public function illegalStringOffsetQuoted(
        string $offset,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitWarning(
            'Illegal string offset "' . $offset . '"',
            $context,
            $frame,
            $file
        );
    }

    /**
     * Zend/zend_execute.c — multi-byte RHS assigned to a string offset keeps only byte 0 (#22380).
     */
    public function onlyFirstByteAssignedToStringOffset(
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitWarning(
            Variable::STRING_OFFSET_FIRST_BYTE_WARNING,
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
        $this->emitWarning(self::undefinedVariableMessage($name), $context, $frame, $file);
    }

    public static function undefinedVariableMessage(string $name): string
    {
        return "Undefined variable \${$name}";
    }

    /**
     * Zend E_WARNING for undefined $GLOBALS['name'] read (zend_execute.c, #17482).
     */
    public function undefinedGlobalVariable(
        string $name,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitWarning(self::undefinedGlobalVariableMessage($name), $context, $frame, $file);
    }

    public static function undefinedGlobalVariableMessage(string $name): string
    {
        return "Undefined global variable \${$name}";
    }

    public function undefinedArrayKey(
        Variable $index,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $key = $this->formatArrayKey(HashTable::normalizeIndexKey($index, 'Illegal offset type', false));
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
     * Zend E_WARNING for dim-read on a resource container (zend_execute.c, #30028).
     *
     * PHP 8.3+ shortens "on value of type resource" → "on resource"; PROFILE≥8.3 matches.
     */
    public static function arrayOffsetOnResourceMessage(): string
    {
        if (version_compare(\PHPCompiler\CompilerVersion::languageProfileVersion(), '8.3.0', '>=')) {
            return 'Trying to access array offset on resource';
        }

        return self::arrayOffsetOnNonContainerMessage('resource');
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
     * Zend E_WARNING for ZEND_FETCH_DIM_R when the container is a resource (#30028).
     */
    public function arrayOffsetOnResource(
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitWarning(
            self::arrayOffsetOnResourceMessage(),
            $context,
            $frame,
            $file
        );
    }

    /**
     * Zend E_WARNING for undefined instance property read (zend_object_handlers.c, #14938).
     */
    public function undefinedPropertyRead(
        string $className,
        string $propertyName,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitWarning(
            sprintf('Undefined property: %s::$%s', $className, $propertyName),
            $context,
            $frame,
            $file
        );
    }

    /**
     * Zend E_NOTICE when a declared static property is accessed via -> / ?->
     * (zend_object_handlers.c zend_get_property_offset; #30017).
     *
     * Uses the receiver class name (not the declaring class) in the message.
     */
    public function accessingStaticPropertyAsNonStatic(
        string $className,
        string $propertyName,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitNotice(
            sprintf('Accessing static property %s::$%s as non static', $className, $propertyName),
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

    /**
     * Zend E_NOTICE when `&$obj->prop` routes through by-value `__get` (zend_object_handlers.c, #25688).
     */
    public function indirectModificationOfOverloadedProperty(
        string $className,
        string $propertyName,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        $this->emitNotice(
            sprintf(
                'Indirect modification of overloaded property %s::$%s has no effect',
                $className,
                $propertyName
            ),
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
            || [] !== $this->handlerStack) {
            if ($this->dispatchUserHandler($context, $frame, self::E_NOTICE, $message, $file, $line)) {
                return;
            }
        }
        // Zend records error_get_last() even when error_reporting(0) or @ silences display.
        $this->recordLastError(self::E_NOTICE, $message, $file, $line);
        if (!$this->shouldWriteCliStderr(self::E_NOTICE)) {
            return;
        }
        $this->writeCliStderr(self::E_NOTICE, $message, $file, $line);
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
            || [] !== $this->handlerStack) {
            if ($this->dispatchUserHandler($context, $frame, self::E_WARNING, $message, $file, $line)) {
                return;
            }
        }
        // Zend records error_get_last() even when error_reporting(0) or @ silences display.
        $this->recordLastError(self::E_WARNING, $message, $file, $line);
        if (!$this->shouldWriteCliStderr(self::E_WARNING)) {
            return;
        }
        $this->writeCliStderr(self::E_WARNING, $message, $file, $line);
    }

    /**
     * Zend E_DEPRECATED from engine/builtins (zend_errors.c; #13139).
     *
     * Records {@see error_get_last()} even when {@see beginSilence()} (@) cleared error_reporting.
     */
    public function internalDeprecated(
        string $message,
        ?Context $context = null,
        ?Frame $frame = null,
        ?string $file = null,
        int $line = 0
    ): void {
        [$file, $line] = $this->resolveDisplayLocation($frame, $file, $line);
        if (0 !== ($this->errorReporting & self::E_DEPRECATED)
            || [] !== $this->handlerStack) {
            if ($this->dispatchUserHandler($context, $frame, self::E_DEPRECATED, $message, $file, $line)) {
                return;
            }
        }
        $this->recordLastError(self::E_DEPRECATED, $message, $file, $line);
        if (!$this->shouldWriteCliStderr(self::E_DEPRECATED)) {
            return;
        }
        $this->writeCliStderr(self::E_DEPRECATED, $message, $file, $line);
    }

    public function deprecatedDynamicProperty(
        string $className,
        string $propertyName,
        ?string $file = null,
        ?Context $context = null,
        ?Frame $frame = null
    ): void {
        $this->internalDeprecated(
            sprintf(
                'Creation of dynamic property %s::$%s is deprecated',
                $className,
                $propertyName
            ),
            $context,
            $frame,
            $file,
            0
        );
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
            || [] !== $this->handlerStack) {
            if ($this->dispatchUserHandler($context, $frame, $level, $message, $file, $line)) {
                // php-src: handler return true swallows the error — including E_USER_ERROR —
                // and continues at the trigger_error() call site (#29216; RFC deprecations_php_8_4).
                return;
            }
        }
        $this->recordLastError($level, $message, $file, $line);
        if (!$this->shouldWriteCliStderr($level)) {
            if (self::E_USER_ERROR === $level) {
                $this->abortUserFatal($level, $message, $file, $line);
            }

            return;
        }
        $this->writeCliStderr($level, $message, $file, $line);
        if (self::E_USER_ERROR === $level) {
            $this->abortUserFatal($level, $message, $file, $line);
        }
    }

    /**
     * Zend E_USER_ERROR — non-recoverable user fatal; must not surface as catchable LogicException (#16747).
     *
     * @return never
     */
    private function abortUserFatal(int $level, string $message, ?string $file, int $line): void
    {
        if (!$this->shouldWriteCliStderr($level)) {
            self::writeCliStderrLine($level, $message, $file, $line);
        }
        throw new ScriptExit(255);
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
            NativeLastError::clear();

            return;
        }
        if (!$this->shouldWriteCliStderr($level)) {
            return;
        }
        $this->writeCliStderr($level, $message, $file, $line);
    }

    /**
     * php-src CLI php_error_cb: stderr when error_reporting includes the level
     * (main/main.c; issues #13486, #13542). display_errors gates extra stdout copy only.
     */
    private function shouldWriteCliStderr(int $level): bool
    {
        return 0 !== ($this->errorReporting & $level);
    }

    /**
     * Zend CLI stderr line (main/main.c php_error_cb).
     *
     * Shared by VM and {@see \PHPCompiler\ext\standard\TriggerErrorJitHelper} (#9293).
     */
    public static function formatCliErrorLine(int $level, string $message, ?string $file, int $line): string
    {
        return self::formatCliErrorLineWithPrefix(self::cliStderrPrefix($level), $message, $file, $line, true);
    }

    /**
     * Zend CLI stdout copy when display_errors=1 (sapi/cli/php_cli.c; issue #18562).
     *
     * Omits the leading "PHP " and uses a single space after the colon.
     */
    public static function formatCliDisplayErrorLine(int $level, string $message, ?string $file, int $line): string
    {
        return self::formatCliErrorLineWithPrefix(self::cliDisplayPrefix($level), $message, $file, $line, false);
    }

    private static function cliStderrPrefix(int $level): string
    {
        return match ($level) {
            self::E_WARNING, self::E_USER_WARNING => 'PHP Warning',
            self::E_NOTICE, self::E_USER_NOTICE => 'PHP Notice',
            self::E_DEPRECATED, self::E_USER_DEPRECATED => 'PHP Deprecated',
            self::E_ERROR, self::E_COMPILE_ERROR, self::E_USER_ERROR => 'PHP Fatal error',
            default => 'PHP Unknown error',
        };
    }

    private static function cliDisplayPrefix(int $level): string
    {
        return match ($level) {
            self::E_WARNING, self::E_USER_WARNING => 'Warning',
            self::E_NOTICE, self::E_USER_NOTICE => 'Notice',
            self::E_DEPRECATED, self::E_USER_DEPRECATED => 'Deprecated',
            self::E_ERROR, self::E_COMPILE_ERROR, self::E_USER_ERROR => 'Fatal error',
            default => 'Unknown error',
        };
    }

    private static function formatCliErrorLineWithPrefix(
        string $prefix,
        string $message,
        ?string $file,
        int $line,
        bool $stderrSpacing
    ): string {
        $colonSpacing = $stderrSpacing ? ':  ' : ': ';
        $formatted = "{$prefix}{$colonSpacing}{$message}";
        if (null !== $file && '' !== $file) {
            $formatted .= " in {$file}";
            if ($line > 0) {
                $formatted .= " on line {$line}";
            }
        }

        return $formatted."\n";
    }

    /**
     * php-src CLI: diagnostics go to stderr when error_reporting includes the level;
     * display_errors=1 mirrors to stdout with the short prefix (main/main.c php_error_cb; #13486, #18562).
     */
    public static function writeCliStderrLine(int $level, string $message, ?string $file, int $line): void
    {
        self::writeCliErrorOutput($level, $message, $file, $line, false);
    }

    public static function writeCliErrorOutput(
        int $level,
        string $message,
        ?string $file,
        int $line,
        bool $displayErrors
    ): void {
        fwrite(STDERR, self::formatCliErrorLine($level, $message, $file, $line));
        if ($displayErrors) {
            echo "\n", self::formatCliDisplayErrorLine($level, $message, $file, $line);
        }
    }

    private function writeCliStderr(int $level, string $message, ?string $file, int $line): void
    {
        self::writeCliErrorOutput($level, $message, $file, $line, $this->displayErrors);
    }

    /**
     * @return array{0: ?string, 1: int}
     */
    private function resolveDisplayLocation(?Frame $frame, ?string $file, int $line): array
    {
        if (null !== $frame) {
            // Builtin handlers run in Internal frames without scriptPath/callSiteLine; Zend
            // attributes warnings to the user call site (parent frame, issue #11163).
            $walk = $frame;
            while (null !== $walk) {
                if ((null === $file || '' === $file) && '' !== $walk->scriptPath) {
                    $file = $walk->scriptPath;
                }
                $walk = $walk->parent;
            }
            if ($line <= 0) {
                $walk = $frame;
                while (null !== $walk) {
                    if ($walk->callSiteLine > 0) {
                        $line = $walk->callSiteLine;
                        break;
                    }
                    $walk = $walk->parent;
                }
            }
            if ($line <= 0) {
                $walk = $frame;
                while (null !== $walk) {
                    if ('' !== $walk->scriptPath) {
                        $opcodeLine = FatalSite::lineFromOpcodes($walk);
                        if ($opcodeLine > 0) {
                            $line = $opcodeLine;
                            break;
                        }
                    }
                    $walk = $walk->parent;
                }
            }
        }

        return [$file, $line];
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

    /**
     * @param callable(Variable): void $visitVar
     */
    public function visitGcRoots(callable $visitVar): void
    {
        foreach ($this->handlerStack as [$handler]) {
            $visitVar($handler);
        }
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
