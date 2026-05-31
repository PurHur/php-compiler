<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmReflection;

/**
 * Shared helpers for Throwable / Exception / Error VM builtins (#195, #3371).
 *
 * php-src: Zend/zend_exceptions.c, Zend/zend_exceptions.h
 */
final class ExceptionSupport
{
    public const CLASS_THROWABLE = 'throwable';
    public const CLASS_EXCEPTION = 'exception';
    public const CLASS_LOGIC_EXCEPTION = 'logicexception';
    public const CLASS_ERROR = 'error';
    public const CLASS_TYPE_ERROR = 'typeerror';
    public const CLASS_VALUE_ERROR = 'valueerror';
    public const CLASS_ARGUMENT_COUNT_ERROR = 'argumentcounterror';
    public const CLASS_PARSE_ERROR = 'parseerror';
    public const CLASS_UNHANDLED_MATCH_ERROR = 'unhandledmatcherror';
    public const CLASS_ARITHMETIC_ERROR = 'arithmeticerror';
    public const CLASS_DIVISION_BY_ZERO_ERROR = 'divisionbyzeroerror';
    public const CLASS_ASSERTION_ERROR = 'assertionerror';

    public const PROP_MESSAGE = 'message';
    public const PROP_CODE = 'code';
    public const PROP_FILE = 'file';
    public const PROP_LINE = 'line';

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

    public static function classEntryImplementsThrowable(ClassEntry $class, ?Context $ctx = null): bool
    {
        if (null !== $ctx) {
            return InterfaceCheck::entryImplements($class, self::CLASS_THROWABLE, $ctx);
        }
        $lc = strtolower($class->name);
        if (self::CLASS_EXCEPTION === $lc || self::CLASS_ERROR === $lc || self::CLASS_LOGIC_EXCEPTION === $lc) {
            return true;
        }
        if (self::isBuiltinErrorSubclass($lc)) {
            return true;
        }

        return in_array(self::CLASS_THROWABLE, $class->interfaces, true);
    }

    public static function isBuiltinErrorSubclass(string $lc): bool
    {
        return in_array($lc, [
            self::CLASS_TYPE_ERROR,
            self::CLASS_VALUE_ERROR,
            self::CLASS_ARGUMENT_COUNT_ERROR,
            self::CLASS_PARSE_ERROR,
            self::CLASS_UNHANDLED_MATCH_ERROR,
            self::CLASS_ARITHMETIC_ERROR,
            self::CLASS_DIVISION_BY_ZERO_ERROR,
            self::CLASS_ASSERTION_ERROR,
        ], true);
    }

    public static function initFromConstruct(ObjectEntry $receiver, Frame $frame, int $messageArgIndex = 1): void
    {
        $message = '';
        if (array_key_exists($messageArgIndex, $frame->calledArgs)) {
            $msgVar = $frame->calledArgs[$messageArgIndex]->resolveIndirect();
            if (Variable::TYPE_NULL !== $msgVar->type) {
                $message = VmReflection::stringArg($frame->calledArgs[$messageArgIndex], 'Exception::__construct() message');
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
        $receiver->constructed = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
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
        return 0;
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
     * Map a materialized VM Throwable object to a native PHP exception for PHPUnit / uncaught exit (#3114, #195).
     */
    public static function nativeUncaughtThrowable(ObjectEntry $entry, string $message): \Throwable
    {
        $lc = strtolower($entry->class->name);

        return match ($lc) {
            self::CLASS_VALUE_ERROR => new \ValueError($message),
            self::CLASS_TYPE_ERROR => new \TypeError($message),
            self::CLASS_DIVISION_BY_ZERO_ERROR => new \DivisionByZeroError($message),
            self::CLASS_ARGUMENT_COUNT_ERROR => new \ArgumentCountError($message),
            self::CLASS_PARSE_ERROR => new \ParseError($message),
            self::CLASS_UNHANDLED_MATCH_ERROR => new \UnhandledMatchError($message),
            self::CLASS_ASSERTION_ERROR => new \AssertionError($message),
            self::CLASS_ERROR => new \Error($message),
            self::CLASS_LOGIC_EXCEPTION => new \LogicException($message),
            self::CLASS_EXCEPTION => new \Exception($message),
            default => new \Exception($message),
        };
    }
}
