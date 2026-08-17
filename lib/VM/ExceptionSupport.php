<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\ThrowableManifest;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmNullNumberParamDeprecation;
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
    public const CLASS_DATE_MALFORMED_INTERVAL_STRING_EXCEPTION = ThrowableManifest::LC_DATE_MALFORMED_INTERVAL_STRING_EXCEPTION;
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
     * Zend zend_implement_throwable — user class/enum cannot list Throwable unless root
     * extends Exception or Error (#25869, Zend/zend_exceptions.c).
     */
    public static function isThrowableInterfaceLc(string $ifaceLc): bool
    {
        return self::CLASS_THROWABLE === strtolower(ltrim($ifaceLc, '\\'));
    }

    /**
     * @return string|null Zend fatal body (no "Fatal error:" prefix), or null when allowed
     */
    public static function userImplementsThrowableForbiddenMessage(
        string $subjectDisplay,
        bool $isEnum,
        ?string $parentLc = null,
        ?Context $ctx = null,
    ): ?string {
        if ($isEnum) {
            return sprintf('Enum %s cannot implement interface Throwable', $subjectDisplay);
        }
        if (self::parentRootIsExceptionOrError($parentLc, $ctx)) {
            return null;
        }

        return sprintf(
            'Class %s cannot implement interface Throwable, extend Exception or Error instead',
            $subjectDisplay
        );
    }

    /**
     * Walk parentLc / ClassEntry chain; allow when root is Exception or Error (or subclass).
     */
    public static function parentRootIsExceptionOrError(?string $parentLc, ?Context $ctx): bool
    {
        if (null === $parentLc || '' === $parentLc) {
            return false;
        }
        $lc = strtolower(ltrim($parentLc, '\\'));
        $seen = [];
        while ('' !== $lc) {
            if (isset($seen[$lc])) {
                return false;
            }
            $seen[$lc] = true;
            if (
                self::CLASS_EXCEPTION === $lc
                || self::CLASS_ERROR === $lc
                || ThrowableManifest::isDescendantOf($lc, self::CLASS_EXCEPTION)
                || ThrowableManifest::isDescendantOf($lc, self::CLASS_ERROR)
            ) {
                return true;
            }
            if (null === $ctx || !isset($ctx->classes[$lc])) {
                return false;
            }
            $next = $ctx->classes[$lc]->parentLc;
            if (null === $next || '' === $next) {
                return false;
            }
            $lc = strtolower(ltrim($next, '\\'));
        }

        return false;
    }

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

    /**
     * Zend zim_Exception_* / zim_Error_* ACE label — declaring root, not runtime class (#30895).
     *
     * php-src: Zend/zend_exceptions.c — methods live on Exception or Error; subclasses inherit
     * the root name in ArgumentCountError ("Error::getMessage()", not "TypeError::getMessage()").
     */
    public static function throwableMethodFunctionLabel(ObjectEntry $receiver, string $methodName): string
    {
        $methodLc = strtolower($methodName);
        $declLc = $receiver->class->methodDeclaringClassLc[$methodLc] ?? null;
        if (null === $declLc || '' === $declLc) {
            $lc = strtolower(ltrim($receiver->class->name, '\\'));
            $declLc = (self::CLASS_ERROR === $lc || ThrowableManifest::isDescendantOf($lc, self::CLASS_ERROR))
                ? self::CLASS_ERROR
                : self::CLASS_EXCEPTION;
        }
        $root = self::CLASS_ERROR === $declLc ? 'Error' : 'Exception';
        $display = $receiver->class->methodNames[$methodLc] ?? $methodName;

        return $root.'::'.$display;
    }

    /**
     * Zend Z_PARAM_LONG / typed int $code for Exception/Error/ErrorException::__construct (#28797).
     *
     * Weak mode coerces numeric strings, floats, and bools; declare(strict_types=1) requires int.
     * php-src: Zend/zend_exceptions.c — zend_parse_parameters(..., "|SlO!", …) / typed stubs.
     *
     * @throws \TypeError when the operand cannot be coerced like Zend
     */
    public static function coerceConstructCodeArg(
        Frame $frame,
        Variable $codeVar,
        string $className,
        int $userArgIndex = 1
    ): int {
        $codeVar = $codeVar->resolveIndirect();
        $function = "{$className}::__construct";
        if (InternalStrictArg::isCallerStrict($frame)) {
            if (Variable::TYPE_INTEGER !== $codeVar->type) {
                throw new \TypeError(self::constructCodeTypeError(
                    $function,
                    $userArgIndex,
                    EnumCaseSupport::typeNameForVariable($codeVar)
                ));
            }

            return $codeVar->toInt();
        }

        switch ($codeVar->type) {
            case Variable::TYPE_INTEGER:
                return $codeVar->toInt();
            case Variable::TYPE_BOOLEAN:
                return $codeVar->toBool() ? 1 : 0;
            case Variable::TYPE_NULL:
                // Zend 8.1+ typed int $code — null coerces to 0 with E_DEPRECATED.
                VmNullNumberParamDeprecation::emit(
                    $frame,
                    $function,
                    $userArgIndex + 1,
                    'code',
                    'int'
                );

                return 0;
            case Variable::TYPE_FLOAT:
                $float = $codeVar->toFloat();
                if (!\is_finite($float)) {
                    throw new \TypeError(self::constructCodeTypeError($function, $userArgIndex, 'float'));
                }
                $vm = \PHPCompiler\VM::running();
                $ctx = $vm?->context ?? $frame->vmContext ?? $frame->parent?->vmContext;
                if (null !== $ctx) {
                    VmMath::warnFloatToIntPrecisionLoss(
                        $float,
                        $ctx,
                        $vm?->currentExecutingFrame() ?? $frame
                    );
                }

                return VmMath::floatToZendLong($float);
            case Variable::TYPE_STRING:
                $s = $codeVar->toString();
                if ('' === $s || !\is_numeric($s)) {
                    throw new \TypeError(self::constructCodeTypeError($function, $userArgIndex, 'string'));
                }

                return (int) (float) $s;
            default:
                throw new \TypeError(self::constructCodeTypeError(
                    $function,
                    $userArgIndex,
                    EnumCaseSupport::typeNameForVariable($codeVar)
                ));
        }
    }

    private static function constructCodeTypeError(
        string $function,
        int $userArgIndex,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($code) must be of type int, %s given',
            $function,
            $userArgIndex + 1,
            $given
        );
    }

    public static function initFromConstruct(ObjectEntry $receiver, Frame $frame, int $messageArgIndex = 1): void
    {
        $message = '';
        if (array_key_exists($messageArgIndex, $frame->calledArgs)) {
            $msgVar = $frame->calledArgs[$messageArgIndex]->resolveIndirect();
            if (Variable::TYPE_NULL !== $msgVar->type) {
                $className = $receiver->class->name;
                $message = VmString::internalMethodStringArgForFrame(
                    $frame,
                    $messageArgIndex,
                    "{$className}::__construct",
                    0,
                    'message'
                );
            }
        }
        $code = 0;
        if (array_key_exists($messageArgIndex + 1, $frame->calledArgs)) {
            $code = self::coerceConstructCodeArg(
                $frame,
                $frame->calledArgs[$messageArgIndex + 1],
                $receiver->class->name,
                1
            );
        }
        $receiver->getProperty(self::PROP_MESSAGE)->string($message);
        $receiver->getProperty(self::PROP_CODE)->int($code);
        $file = self::throwSiteFile($frame);
        $receiver->getProperty(self::PROP_FILE)->string($file);
        $lineProp = $receiver->getProperty(self::PROP_LINE);
        $line = 0;
        $lineVar = $lineProp->resolveIndirect();
        if (Variable::TYPE_INTEGER === $lineVar->type) {
            $line = $lineVar->toInt();
        }
        if ($line <= 0) {
            $line = self::throwSiteLine($frame);
        }
        $lineProp->int(\PHPCompiler\ext\standard\VmEval::unwrapEvalThrowableLine($file, $line));
        if (array_key_exists($messageArgIndex + 2, $frame->calledArgs)) {
            $prevArg = $frame->calledArgs[$messageArgIndex + 2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $prevArg->type) {
                // Zend: ?Throwable $previous — TypeError, not LogicException (#28798).
                self::setExceptionPrevious(
                    $receiver,
                    $prevArg,
                    $receiver->class->name,
                    2,
                    $frame->vmContext ?? $frame->parent?->vmContext
                );
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
                $message = VmString::internalMethodStringArgForFrame(
                    $frame,
                    1,
                    "{$className}::__construct",
                    0,
                    'message'
                );
            }
        }
        $code = 0;
        if (array_key_exists(2, $frame->calledArgs)) {
            $code = self::coerceConstructCodeArg($frame, $frame->calledArgs[2], $className, 1);
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
                $file = VmString::internalMethodStringArgForFrame(
                    $frame,
                    4,
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
        $receiver->getProperty(self::PROP_LINE)->int(
            \PHPCompiler\ext\standard\VmEval::unwrapEvalThrowableLine($file, $line)
        );
        if (array_key_exists(6, $frame->calledArgs)) {
            $prevArg = $frame->calledArgs[6]->resolveIndirect();
            if (Variable::TYPE_NULL !== $prevArg->type) {
                // Zend: ?Throwable $previous — Argument #6 (#28798).
                self::setExceptionPrevious(
                    $receiver,
                    $prevArg,
                    $receiver->class->name,
                    5,
                    $frame->vmContext ?? $frame->parent?->vmContext
                );
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
        return \PHPCompiler\ext\standard\VmEval::unwrapEvalThrowableLine(
            self::throwSiteFile($frame),
            FatalSite::lineFromOpcodes($frame)
        );
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
     * Zend eval() ParseError / __FILE__ shape: parent.php(line) : eval()'d code (#4410, #25809).
     *
     * @return array{0: string, 1: int}
     */
    public static function evalFatalSite(Frame $frame, int $evalLine = 1): array
    {
        [$file, $callLine] = self::userFatalSite($frame);
        $callLine = \PHPCompiler\ext\standard\VmEval::evalCallSiteLine($file, $callLine);
        $file = \PHPCompiler\ext\standard\VmEval::zendEvalFilename($file, $callLine);

        return [$file, max(1, $evalLine)];
    }

    public static function stampThrowableSite(ObjectEntry $receiver, string $file, int $line): void
    {
        // Always init typed file/line slots — Zend zend_exception_get_props; uninit
        // prototypes fatal on getFile()/getLine() after engine Errors (#24397).
        $receiver->getProperty(self::PROP_FILE)->string($file);
        $receiver->getProperty(self::PROP_LINE)->int(max(0, $line));
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
    public static function emitNativeUncaughtFatal(
        \Throwable $native,
        ?ObjectEntry $vmEntry = null,
        bool $displayErrors = false,
    ): void {
        self::writeNativeUncaughtFatalBlock($native, true, $vmEntry, $displayErrors);
        throw new ScriptExit(255);
    }

    /**
     * zend_exception_error(ex, E_WARNING) — pending hook throw before a follow-on E_ERROR (#25748).
     *
     * php-src: Zend/zend.c zend_error path clears EG(exception) via zend_exception_error(E_WARNING)
     * before `__debuginfo() must return an array` (zend_object_handlers.c).
     */
    public static function emitNativeUncaughtWarning(
        \Throwable $native,
        ?ObjectEntry $vmEntry = null,
        bool $displayErrors = false,
        ?Variable $traceOverride = null,
    ): void {
        $class = self::uncaughtDisplayClass($native, $vmEntry);
        $message = $native->getMessage();
        $file = $native->getFile();
        $line = $native->getLine();
        // Match zend_exception_error + php_error_cb: Uncaught %Z\n  thrown + " in file on line N".
        $body = "Uncaught {$class}: {$message}";
        if ('' !== $file) {
            $body .= " in {$file}";
            if ($line > 0) {
                $body .= ":{$line}";
            }
        }
        $body .= "\nStack trace:\n".self::formatUncaughtStackTrace($vmEntry, $traceOverride);
        $body .= '  thrown';
        ErrorReporter::writeCliErrorOutput(
            ErrorReporter::E_WARNING,
            $body,
            '' !== $file ? $file : null,
            $line,
            $displayErrors
        );
    }

    /**
     * Zend zend_exceptions.c — finally throw over pending try uncaught fatal (#5867, #7342).
     *
     * @return never
     */
    public static function emitNativeUncaughtFatalWithNext(
        \Throwable $primary,
        \Throwable $next,
        bool $displayErrors = false,
    ): void {
        self::writeNativeUncaughtFatalBlock($primary, false, null, $displayErrors);
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

    private static function writeNativeUncaughtFatalBlock(
        \Throwable $native,
        bool $includeThrownIn,
        ?ObjectEntry $vmEntry = null,
        bool $displayErrors = false,
    ): void {
        $class = self::uncaughtDisplayClass($native, $vmEntry);
        $message = $native->getMessage();
        $file = $native->getFile();
        $line = $native->getLine();
        $body = "Uncaught {$class}: {$message}";
        if ('' !== $file) {
            $body .= " in {$file}";
            if ($line > 0) {
                $body .= ":{$line}";
            }
        }
        $stackTrace = self::formatUncaughtStackTrace($vmEntry);
        $thrownIn = '';
        if ($includeThrownIn && '' !== $file && $line > 0) {
            $thrownIn = "  thrown in {$file} on line {$line}\n";
        }

        fwrite(STDERR, "PHP Fatal error:  {$body}\n");
        fwrite(STDERR, "Stack trace:\n");
        fwrite(STDERR, $stackTrace);
        if ('' !== $thrownIn) {
            fwrite(STDERR, $thrownIn);
        }

        // php-src CLI: display_errors mirrors uncaught fatals to stdout without the PHP prefix
        // (sapi/cli/php_cli.c / main/main.c php_error_cb; issue #18561).
        if ($displayErrors) {
            echo "Fatal error: {$body}\n";
            echo "Stack trace:\n";
            echo $stackTrace;
            if ('' !== $thrownIn) {
                echo $thrownIn;
            }
        }
    }

    /**
     * Zend uncaught class name — prefer VM entry (FiberError cannot be instantiated natively; #28832).
     */
    private static function uncaughtDisplayClass(\Throwable $native, ?ObjectEntry $vmEntry): string
    {
        if (null !== $vmEntry && '' !== $vmEntry->class->name) {
            return $vmEntry->class->name;
        }

        return $native::class;
    }

    private static function formatUncaughtStackTrace(
        ?ObjectEntry $vmEntry,
        ?Variable $traceOverride = null,
    ): string {
        if (null !== $traceOverride) {
            $override = $traceOverride->resolveIndirect();
            if (Variable::TYPE_ARRAY === $override->type && $override->toArray()->getNumElements() > 0) {
                return ExceptionTraceFormat::asString($override)."\n";
            }
        }
        if (null !== $vmEntry) {
            $trace = ExceptionTrace::resolveTraceVariable($vmEntry);
            if (Variable::TYPE_ARRAY === $trace->type && $trace->toArray()->getNumElements() > 0) {
                return ExceptionTraceFormat::asString($trace)."\n";
            }
        }

        return "#0 {main}\n";
    }

    /** Safe read for Throwable slots that may stay at typed prototype without a value (#6357). */
    public static function readThrowableMessage(ObjectEntry $entry): string
    {
        $message = self::readOptionalStringProperty($entry, self::PROP_MESSAGE);

        return null !== $message && '' !== $message ? $message : 'Exception';
    }

    /** Safe file read — uninit typed prototype → '' (#24397, #6357). */
    public static function readThrowableFile(ObjectEntry $entry): string
    {
        return self::readOptionalStringProperty($entry, self::PROP_FILE) ?? '';
    }

    /** Safe line read — uninit typed prototype → 0 (#24397, #6357). */
    public static function readThrowableLine(ObjectEntry $entry): int
    {
        return self::readOptionalIntProperty($entry, self::PROP_LINE) ?? 0;
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
        // Preserve creation/rethrow line; only stamp inline `throw new` when still unset (#195, #18579).
        $existing = self::readOptionalIntProperty($obj, self::PROP_LINE) ?? 0;
        if ($existing > 0) {
            return;
        }
        if ($line < 1) {
            $line = 1;
        }
        $file = self::readThrowableFile($obj);
        $obj->getProperty(self::PROP_LINE)->int(
            \PHPCompiler\ext\standard\VmEval::unwrapEvalThrowableLine($file, $line)
        );
    }

    /**
     * Zend zend_exception_set_previous — link $previous when outer has none (#5486).
     *
     * Construct call sites pass $constructClassName so a bad $previous raises Zend's
     * TypeError (`?Throwable`) instead of a host LogicException (#28798).
     * Internal chain (finally) omits the class name and skips silently on junk.
     */
    public static function setExceptionPrevious(
        ObjectEntry $receiver,
        Variable $previous,
        ?string $constructClassName = null,
        int $previousParamIndex = 2,
        ?Context $ctx = null
    ): void {
        $previous = $previous->resolveIndirect();
        if (Variable::TYPE_NULL === $previous->type) {
            return;
        }
        $isObject = Variable::TYPE_OBJECT === $previous->type;
        $implements = $isObject && self::objectImplementsThrowable($previous->toObject(), $ctx);
        if (!$implements) {
            if (null === $constructClassName) {
                // Internal finally-chain: both sides should already be Throwable.
                return;
            }
            throw ParamTypeError::forUserCallWithExpectedType(
                $constructClassName.'::__construct',
                $previousParamIndex,
                'previous',
                '?Throwable',
                $previous,
                '',
                0
            );
        }
        if ($receiver === $previous->toObject()) {
            return;
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
            } elseif ('soapfault' === $lc && \class_exists('SoapFault', false)) {
                // Preserve SoapFault actor/detail/_name across VM→native rematerialize (#20219).
                $code = self::readOptionalStringProperty($entry, 'faultcode') ?? '';
                $string = self::readOptionalStringProperty($entry, 'faultstring') ?? $message;
                $actor = self::readOptionalStringProperty($entry, 'faultactor');
                $detailVar = $entry->hasProperty('detail')
                    ? $entry->getProperty('detail')->resolveIndirect()
                    : null;
                $detail = null;
                if (null !== $detailVar && Variable::TYPE_NULL !== $detailVar->type) {
                    if (Variable::TYPE_STRING === $detailVar->type) {
                        $detail = $detailVar->toString();
                    } elseif (Variable::TYPE_INTEGER === $detailVar->type) {
                        $detail = $detailVar->toInt();
                    } elseif (Variable::TYPE_BOOLEAN === $detailVar->type) {
                        $detail = $detailVar->toBool();
                    } elseif (Variable::TYPE_FLOAT === $detailVar->type) {
                        $detail = $detailVar->toFloat();
                    } else {
                        $detail = $detailVar->toString();
                    }
                }
                $name = self::readOptionalStringProperty($entry, '_name');
                $native = new \SoapFault(
                    '' !== $code ? $code : null,
                    $string,
                    $actor,
                    $detail,
                    $name
                );
            } else {
                $native = new $nativeClass($message);
            }
        } elseif (self::CLASS_FIBER_ERROR === $lc) {
            // FiberError is reserved for internal use in Zend; cannot be instantiated from userland PHP.
            // Map to native Error for bubble/test-runner paths; uncaught CLI uses VM class name (#28832).
            $native = new \Error($message);
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
