<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\ThrowableManifest;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/**
 * Shared helpers for Throwable / Exception / Error VM builtins (#195, #3371).
 *
 * php-src: Zend/zend_exceptions.c, Zend/zend_exceptions.h
 */
final class ExceptionSupport
{
    public const CLASS_THROWABLE = ThrowableManifest::LC_THROWABLE;
    public const CLASS_EXCEPTION = ThrowableManifest::LC_EXCEPTION;
    public const CLASS_LOGIC_EXCEPTION = ThrowableManifest::LC_LOGIC_EXCEPTION;
    public const CLASS_BAD_FUNCTION_CALL_EXCEPTION = ThrowableManifest::LC_BAD_FUNCTION_CALL_EXCEPTION;
    public const CLASS_BAD_METHOD_CALL_EXCEPTION = ThrowableManifest::LC_BAD_METHOD_CALL_EXCEPTION;
    public const CLASS_DOMAIN_EXCEPTION = ThrowableManifest::LC_DOMAIN_EXCEPTION;
    public const CLASS_INVALID_ARGUMENT_EXCEPTION = ThrowableManifest::LC_INVALID_ARGUMENT_EXCEPTION;
    public const CLASS_LENGTH_EXCEPTION = ThrowableManifest::LC_LENGTH_EXCEPTION;
    public const CLASS_OUT_OF_RANGE_EXCEPTION = ThrowableManifest::LC_OUT_OF_RANGE_EXCEPTION;
    public const CLASS_RUNTIME_EXCEPTION = ThrowableManifest::LC_RUNTIME_EXCEPTION;
    public const CLASS_OUT_OF_BOUNDS_EXCEPTION = ThrowableManifest::LC_OUT_OF_BOUNDS_EXCEPTION;
    public const CLASS_OVERFLOW_EXCEPTION = ThrowableManifest::LC_OVERFLOW_EXCEPTION;
    public const CLASS_RANGE_EXCEPTION = ThrowableManifest::LC_RANGE_EXCEPTION;
    public const CLASS_UNDERFLOW_EXCEPTION = ThrowableManifest::LC_UNDERFLOW_EXCEPTION;
    public const CLASS_UNEXPECTED_VALUE_EXCEPTION = ThrowableManifest::LC_UNEXPECTED_VALUE_EXCEPTION;
    public const CLASS_ERROR_EXCEPTION = ThrowableManifest::LC_ERROR_EXCEPTION;
    public const CLASS_REFLECTION_EXCEPTION = ThrowableManifest::LC_REFLECTION_EXCEPTION;
    public const CLASS_CLOSED_GENERATOR_EXCEPTION = ThrowableManifest::LC_CLOSED_GENERATOR_EXCEPTION;
    public const CLASS_REQUEST_PARSE_BODY_EXCEPTION = ThrowableManifest::LC_REQUEST_PARSE_BODY_EXCEPTION;
    public const CLASS_DATE_EXCEPTION = ThrowableManifest::LC_DATE_EXCEPTION;
    public const CLASS_DATE_INVALID_TIME_ZONE_EXCEPTION = ThrowableManifest::LC_DATE_INVALID_TIME_ZONE_EXCEPTION;
    public const CLASS_DATE_MALFORMED_INTERVAL_EXCEPTION = ThrowableManifest::LC_DATE_MALFORMED_INTERVAL_EXCEPTION;
    public const CLASS_DATE_MALFORMED_PERIOD_EXCEPTION = ThrowableManifest::LC_DATE_MALFORMED_PERIOD_EXCEPTION;
    public const CLASS_DATE_ERROR = ThrowableManifest::LC_DATE_ERROR;
    public const CLASS_DATE_OBJECT_ERROR = ThrowableManifest::LC_DATE_OBJECT_ERROR;
    public const CLASS_DATE_RANGE_ERROR = ThrowableManifest::LC_DATE_RANGE_ERROR;
    public const CLASS_ERROR = ThrowableManifest::LC_ERROR;
    public const CLASS_TYPE_ERROR = ThrowableManifest::LC_TYPE_ERROR;
    public const CLASS_VALUE_ERROR = ThrowableManifest::LC_VALUE_ERROR;
    public const CLASS_ARGUMENT_COUNT_ERROR = ThrowableManifest::LC_ARGUMENT_COUNT_ERROR;
    public const CLASS_PARSE_ERROR = ThrowableManifest::LC_PARSE_ERROR;
    public const CLASS_COMPILE_ERROR = ThrowableManifest::LC_COMPILE_ERROR;
    public const CLASS_UNHANDLED_MATCH_ERROR = ThrowableManifest::LC_UNHANDLED_MATCH_ERROR;
    public const CLASS_ARITHMETIC_ERROR = ThrowableManifest::LC_ARITHMETIC_ERROR;
    public const CLASS_DIVISION_BY_ZERO_ERROR = ThrowableManifest::LC_DIVISION_BY_ZERO_ERROR;
    public const CLASS_ASSERTION_ERROR = ThrowableManifest::LC_ASSERTION_ERROR;
    public const CLASS_FIBER_ERROR = ThrowableManifest::LC_FIBER_ERROR;
    public const CLASS_FIBER_STACK_OVERFLOW = ThrowableManifest::LC_FIBER_STACK_OVERFLOW;

    public const PROP_MESSAGE = 'message';
    public const PROP_CODE = 'code';
    /** Zend zend_exceptions.c — ErrorException severity (#6732). */
    public const PROP_SEVERITY = 'severity';
    public const PROP_FILE = 'file';
    public const PROP_LINE = 'line';
    /** Zend zend_exceptions.c — chained Throwable (#5104, #5486). */
    public const PROP_PREVIOUS = 'previous';
    /** Zend zend_exceptions.c — captured stack on throw (#3351, #7159). */
    public const PROP_TRACE = 'trace';

    /** Zend zend_exceptions.c — cached __toString() representation on Exception (#10720). */
    public const PROP_STRING = 'string';

    /** Zend zend_exceptions.c — throw non-Throwable object raises Error (#5223, #5727). */
    public const THROW_NON_THROWABLE_MESSAGE = 'Cannot throw objects that do not implement Throwable';

    /** Zend zend_execute.c — throw scalar/array raises Error (#5727, re-#9488). */
    public const THROW_ONLY_OBJECTS_MESSAGE = 'Can only throw objects';

    /**
     * Zend zend_throw_non_object — message depends on operand kind (#5727, #9488).
     */
    public static function throwNormalizeErrorMessage(Variable $var): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type || Variable::TYPE_ENUM_CASE === $var->type) {
            return self::THROW_NON_THROWABLE_MESSAGE;
        }

        return self::THROW_ONLY_OBJECTS_MESSAGE;
    }

    public static function requireThrowableObject(Variable $var, string $label, ?Context $ctx = null): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \LogicException("{$label} must be an object");
        }
        $obj = $var->toObject();
        if (!self::objectImplementsThrowable($obj, $ctx)) {
            throw new \LogicException("{$label} must implement Throwable");
        }

        return $obj;
    }

    public static function objectImplementsThrowable(ObjectEntry $obj, ?Context $ctx = null): bool
    {
        return self::classEntryImplementsThrowable($obj->class, $ctx);
    }

    public static function isThrowableVariable(Variable $var, ?Context $ctx = null): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return false;
        }

        return self::objectImplementsThrowable($var->toObject(), $ctx);
    }

    public static function classEntryImplementsThrowable(ClassEntry $class, ?Context $ctx = null): bool
    {
        if (null !== $ctx) {
            return InterfaceCheck::entryImplements($class, self::CLASS_THROWABLE, $ctx);
        }
        $lc = strtolower($class->name);
        if (self::isBuiltinExceptionSubclass($lc) || self::CLASS_ERROR === $lc) {
            return true;
        }
        if (self::isBuiltinErrorSubclass($lc)) {
            return true;
        }

        return in_array(self::CLASS_THROWABLE, $class->interfaces, true);
    }

    public static function isBuiltinExceptionSubclass(string $lc): bool
    {
        return ThrowableManifest::isDescendantOf($lc, self::CLASS_EXCEPTION);
    }

    public static function isBuiltinErrorSubclass(string $lc): bool
    {
        if (self::CLASS_ERROR === $lc) {
            return false;
        }

        return ThrowableManifest::isDescendantOf($lc, self::CLASS_ERROR);
    }

    public static function initFromConstruct(ObjectEntry $receiver, Frame $frame, int $messageArgIndex = 1): void
    {
        $message = '';
        if (array_key_exists($messageArgIndex, $frame->calledArgs)) {
            $msgVar = $frame->calledArgs[$messageArgIndex]->resolveIndirect();
            if (Variable::TYPE_NULL !== $msgVar->type) {
                $className = $receiver->class->name;
                $message = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[$messageArgIndex],
                    "{$className}::__construct",
                    0,
                    'message'
                );
            }
        }
        $code = 0;
        if (array_key_exists($messageArgIndex + 1, $frame->calledArgs)) {
            $codeVar = $frame->calledArgs[$messageArgIndex + 1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $codeVar->type) {
                if (Variable::TYPE_INTEGER !== $codeVar->type) {
                    throw new \LogicException('Exception::__construct() code must be an integer');
                }
                $code = $codeVar->toInt();
            }
        }
        $receiver->getProperty(self::PROP_MESSAGE)->string($message);
        $receiver->getProperty(self::PROP_CODE)->int($code);
        $receiver->getProperty(self::PROP_FILE)->string(self::throwSiteFile($frame));
        $lineProp = $receiver->getProperty(self::PROP_LINE);
        $line = 0;
        $lineVar = $lineProp->resolveIndirect();
        if (Variable::TYPE_INTEGER === $lineVar->type) {
            $line = $lineVar->toInt();
        }
        if ($line <= 0) {
            $line = self::throwSiteLine($frame);
        }
        $lineProp->int($line);
        if (array_key_exists($messageArgIndex + 2, $frame->calledArgs)) {
            $prevArg = $frame->calledArgs[$messageArgIndex + 2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $prevArg->type) {
                self::setExceptionPrevious($receiver, $prevArg);
            }
        }
        $receiver->constructed = true;
        $ctx = $frame->vmContext ?? $frame->parent?->vmContext;
        if (null !== $ctx) {
            ExceptionTrace::captureOnManualConstruct($ctx, $frame, $receiver);
        }
        if (null !== $frame->returnVar) {
            $ret = $frame->returnVar->resolveIndirect();
            // void __construct must not wipe the `new Exception()` temp when returnVar
            // aliases the same slot as $this (FUNCCALL_EXEC_RETURN after TYPE_NEW, #4540).
            if (
                Variable::TYPE_OBJECT !== $ret->type
                || $ret->toObject() !== $receiver
            ) {
                $frame->returnVar->null();
            }
        }
    }

    /**
     * ErrorException::__construct($message, $code, $severity, $filename, $lineno, $previous).
     *
     * php-src: Zend/zend_exceptions.stub.php
     */
    public static function initErrorExceptionFromConstruct(ObjectEntry $receiver, Frame $frame): void
    {
        $className = $receiver->class->name;
        $message = '';
        if (array_key_exists(1, $frame->calledArgs)) {
            $msgVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $msgVar->type) {
                $message = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    "{$className}::__construct",
                    0,
                    'message'
                );
            }
        }
        $code = 0;
        if (array_key_exists(2, $frame->calledArgs)) {
            $codeVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $codeVar->type) {
                if (Variable::TYPE_INTEGER !== $codeVar->type) {
                    throw new \LogicException('ErrorException::__construct() code must be an integer');
                }
                $code = $codeVar->toInt();
            }
        }
        $severity = \E_ERROR;
        if (array_key_exists(3, $frame->calledArgs)) {
            $severityVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $severityVar->type) {
                if (Variable::TYPE_INTEGER !== $severityVar->type) {
                    throw new \LogicException('ErrorException::__construct() severity must be an integer');
                }
                $severity = $severityVar->toInt();
            }
        }
        $receiver->getProperty(self::PROP_MESSAGE)->string($message);
        $receiver->getProperty(self::PROP_CODE)->int($code);
        $receiver->getProperty(self::PROP_SEVERITY)->int($severity);
        $file = self::throwSiteFile($frame);
        if (array_key_exists(4, $frame->calledArgs)) {
            $fileVar = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_NULL !== $fileVar->type) {
                $file = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[4],
                    "{$className}::__construct",
                    3,
                    'filename'
                );
            }
        }
        $receiver->getProperty(self::PROP_FILE)->string($file);
        $line = 0;
        if (array_key_exists(5, $frame->calledArgs)) {
            $lineVar = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_NULL !== $lineVar->type) {
                if (Variable::TYPE_INTEGER !== $lineVar->type) {
                    throw new \LogicException('ErrorException::__construct() lineno must be an integer');
                }
                $line = $lineVar->toInt();
            }
        }
        if ($line <= 0) {
            $line = self::throwSiteLine($frame);
        }
        $receiver->getProperty(self::PROP_LINE)->int($line);
        if (array_key_exists(6, $frame->calledArgs)) {
            $prevArg = $frame->calledArgs[6]->resolveIndirect();
            if (Variable::TYPE_NULL !== $prevArg->type) {
                self::setExceptionPrevious($receiver, $frame->calledArgs[6]);
            }
        }
        $receiver->constructed = true;
        $ctx = $frame->vmContext ?? $frame->parent?->vmContext;
        if (null !== $ctx) {
            ExceptionTrace::captureOnManualConstruct($ctx, $frame, $receiver);
        }
        if (null !== $frame->returnVar) {
            $ret = $frame->returnVar->resolveIndirect();
            if (
                Variable::TYPE_OBJECT !== $ret->type
                || $ret->toObject() !== $receiver
            ) {
                $frame->returnVar->null();
            }
        }
    }

    public static function throwSiteFile(Frame $frame): string
    {
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if ('' !== $f->scriptPath) {
                return $f->scriptPath;
            }
        }

        return '';
    }

    public static function throwSiteLine(Frame $frame): int
    {
        return FatalSite::lineFromOpcodes($frame);
    }

    /**
     * User call-site for uncaught builtin fatals (#6334).
     *
     * @return array{0: string, 1: int}
     */
    public static function userFatalSite(Frame $frame): array
    {
        return FatalSite::userSite($frame);
    }

    /**
     * Zend eval() ParseError file shape: parent.php(line) : eval()'d code (#4410).
     *
     * @return array{0: string, 1: int}
     */
    public static function evalFatalSite(Frame $frame, int $evalLine = 1): array
    {
        [$file, $callLine] = self::userFatalSite($frame);
        if ($callLine > 0) {
            $file = $file.'('.$callLine.') : '.\PHPCompiler\ext\standard\VmEval::EVAL_FILENAME;
        } else {
            $file = $file.' : '.\PHPCompiler\ext\standard\VmEval::EVAL_FILENAME;
        }

        return [$file, max(1, $evalLine)];
    }

    public static function stampThrowableSite(ObjectEntry $receiver, string $file, int $line): void
    {
        if ('' !== $file) {
            $receiver->getProperty(self::PROP_FILE)->string($file);
        }
        if ($line > 0) {
            $receiver->getProperty(self::PROP_LINE)->int($line);
        }
    }

    public static function applyNativeLocation(\Throwable $native, string $file, int $line): void
    {
        if ('' === $file && $line <= 0) {
            return;
        }
        try {
            $ref = new \ReflectionObject($native);
            if ('' !== $file && $ref->hasProperty('file')) {
                $ref->getProperty('file')->setValue($native, $file);
            }
            if ($ref->hasProperty('line')) {
                $ref->getProperty('line')->setValue($native, max(0, $line));
            }
        } catch (\ReflectionException) {
        }
    }

    /**
     * Zend SAPI uncaught fatal — user site only, no VM re-dispatch frames (#6334, #7343).
     *
     * @return never
     */
    public static function emitNativeUncaughtFatal(\Throwable $native): void
    {
        self::writeNativeUncaughtFatalBlock($native, true);
        throw new ScriptExit(255);
    }

    /**
     * Zend zend_exceptions.c — finally throw over pending try uncaught fatal (#5867, #7342).
     *
     * @return never
     */
    public static function emitNativeUncaughtFatalWithNext(\Throwable $primary, \Throwable $next): void
    {
        self::writeNativeUncaughtFatalBlock($primary, false);
        $message = $next->getMessage();
        $file = $next->getFile();
        $line = $next->getLine();
        $nextFatal = "Next Exception: {$message}";
        if ('' !== $file) {
            $nextFatal .= " in {$file}";
            if ($line > 0) {
                $nextFatal .= ":{$line}";
            }
        }
        fwrite(STDERR, "\n{$nextFatal}\n");
        fwrite(STDERR, "Stack trace:\n");
        fwrite(STDERR, "#0 {main}\n");
        if ('' !== $file && $line > 0) {
            fwrite(STDERR, "  thrown in {$file} on line {$line}\n");
        }
        throw new ScriptExit(255);
    }

    private static function writeNativeUncaughtFatalBlock(\Throwable $native, bool $includeThrownIn): void
    {
        $class = $native::class;
        $message = $native->getMessage();
        $file = $native->getFile();
        $line = $native->getLine();
        $fatal = "PHP Fatal error:  Uncaught {$class}: {$message}";
        if ('' !== $file) {
            $fatal .= " in {$file}";
            if ($line > 0) {
                $fatal .= ":{$line}";
            }
        }
        fwrite(STDERR, $fatal."\n");
        fwrite(STDERR, "Stack trace:\n");
        fwrite(STDERR, "#0 {main}\n");
        if ($includeThrownIn && '' !== $file && $line > 0) {
            fwrite(STDERR, "  thrown in {$file} on line {$line}\n");
        }
    }

    /** Safe read for Throwable slots that may stay at typed prototype without a value (#6357). */
    public static function readThrowableMessage(ObjectEntry $entry): string
    {
        $message = self::readOptionalStringProperty($entry, self::PROP_MESSAGE);

        return null !== $message && '' !== $message ? $message : 'Exception';
    }

    private static function readOptionalStringProperty(ObjectEntry $entry, string $prop): ?string
    {
        try {
            return $entry->getProperty($prop)->optionalScalarString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function readOptionalIntProperty(ObjectEntry $entry, string $prop): ?int
    {
        try {
            return $entry->getProperty($prop)->optionalScalarInt();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Stamp throw-statement line on a Throwable before dispatch (#195). */
    public static function stampThrowLine(Variable $thrown, int $line): void
    {
        $var = $thrown->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return;
        }
        $obj = $var->toObject();
        if (!self::objectImplementsThrowable($obj)) {
            return;
        }
        $lineProp = $obj->getProperty(self::PROP_LINE);
        $existing = 0;
        $lineVar = $lineProp->resolveIndirect();
        if (Variable::TYPE_INTEGER === $lineVar->type) {
            $existing = $lineVar->toInt();
        }
        // Preserve creation/rethrow line; only stamp inline `throw new` when still unset (#195).
        if ($existing > 0) {
            return;
        }
        if ($line < 1) {
            $line = 1;
        }
        $lineProp->int($line);
    }

    /**
     * Zend zend_exception_set_previous — link $previous when outer has none (#5486).
     */
    public static function setExceptionPrevious(ObjectEntry $receiver, Variable $previous): void
    {
        $previous = $previous->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $previous->type) {
            throw new \LogicException('Exception previous must be an object');
        }
        if ($receiver === $previous->toObject()) {
            return;
        }
        if (!self::objectImplementsThrowable($previous->toObject())) {
            throw new \LogicException('Exception previous must implement Throwable');
        }
        $slot = $receiver->getProperty(self::PROP_PREVIOUS)->resolveIndirect();
        if (Variable::TYPE_NULL !== $slot->type) {
            return;
        }
        $receiver->getProperty(self::PROP_PREVIOUS)->copyFrom($previous);
    }

    /** Chain pending try exception onto a throw from finally (zend_exceptions.c, #5486). */
    public static function chainPendingExceptionOnFinallyThrow(Variable $thrown, Variable $pending): void
    {
        if ($thrown === $pending) {
            return;
        }
        $thrown = $thrown->resolveIndirect();
        $pending = $pending->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $thrown->type || Variable::TYPE_OBJECT !== $pending->type) {
            return;
        }
        if ($thrown->toObject() === $pending->toObject()) {
            return;
        }
        self::setExceptionPrevious($thrown->toObject(), $pending);
    }

    /**
     * Map a materialized VM Throwable object to a native PHP exception for PHPUnit / uncaught exit (#3114, #195).
     */
    public static function nativeUncaughtThrowable(ObjectEntry $entry, string $message): \Throwable
    {
        $lc = strtolower($entry->class->name);
        $nativeClass = ThrowableManifest::nativeClassForLc($lc);
        if (null !== $nativeClass) {
            if (self::CLASS_ERROR_EXCEPTION === $lc) {
                $severity = self::readOptionalIntProperty($entry, self::PROP_SEVERITY) ?? \E_ERROR;
                $file = self::readOptionalStringProperty($entry, self::PROP_FILE);
                $line = self::readOptionalIntProperty($entry, self::PROP_LINE);
                $native = new \ErrorException(
                    $message,
                    self::readOptionalIntProperty($entry, self::PROP_CODE) ?? 0,
                    $severity,
                    $file,
                    $line ?? 0
                );
            } else {
                $native = new $nativeClass($message);
            }
        } elseif (self::CLASS_FIBER_ERROR === $lc) {
            // FiberError is reserved for internal use in Zend; cannot be instantiated from userland PHP.
            // Map uncaught VM FiberError to a native Error for the test runner / CLI.
            $native = new \Error('FiberError: '.$message);
        } elseif (self::CLASS_FIBER_STACK_OVERFLOW === $lc) {
            if (\class_exists('FiberStackOverflow', false)) {
                $native = new \FiberStackOverflow($message);
            } else {
                $native = new \Error($message);
            }
        } elseif (ThrowableManifest::isDescendantOf($lc, self::CLASS_ERROR)) {
            $native = new \Error($message);
        } else {
            $native = new \Exception($message);
        }
        $file = self::readOptionalStringProperty($entry, self::PROP_FILE);
        $line = self::readOptionalIntProperty($entry, self::PROP_LINE);
        if (null !== $file || null !== $line) {
            self::applyNativeLocation($native, $file ?? '', $line ?? 0);
        }
        // VM Error without a stamped line must not leak native PHP ctor site (#13201).
        if (null === $line || $line <= 0) {
            self::applyNativeLocation($native, $file ?? $native->getFile(), 0);
        }
        $prevVar = $entry->getProperty(self::PROP_PREVIOUS)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $prevVar->type) {
            return $native;
        }
        $prevEntry = $prevVar->toObject();
        if ($prevEntry === $entry) {
            return $native;
        }
        $prevMessage = self::readThrowableMessage($prevEntry);
        $prevNative = self::nativeUncaughtThrowable($prevEntry, $prevMessage);
        if ($native instanceof \Exception && $prevNative instanceof \Throwable) {
            $chained = new \Exception($message, $native->getCode(), $prevNative);
            if (null !== $file || (null !== $line && $line > 0)) {
                self::applyNativeLocation($chained, $file ?? '', $line ?? 0);
            }

            return $chained;
        }
        if ($native instanceof \Error && $prevNative instanceof \Throwable) {
            $chained = new \Error($message, $native->getCode(), $prevNative);
            if (null !== $file || (null !== $line && $line > 0)) {
                self::applyNativeLocation($chained, $file ?? '', $line ?? 0);
            }

            return $chained;
        }

        return $native;
    }
}
