<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmReflection;

/**
 * Shared helpers for Throwable / Exception / Error VM builtins (issue #195).
 *
 * php-src: Zend/zend_exceptions.c, Zend/zend_exceptions.h
 */
final class ExceptionSupport
{
    public const CLASS_THROWABLE = 'throwable';
    public const CLASS_EXCEPTION = 'exception';
    public const CLASS_ERROR = 'error';

    public const PROP_MESSAGE = 'message';
    public const PROP_CODE = 'code';
    public const PROP_FILE = 'file';
    public const PROP_LINE = 'line';

    public static function requireThrowableObject(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \LogicException("{$label} must be an object");
        }
        $obj = $var->toObject();
        if (!self::objectImplementsThrowable($obj)) {
            throw new \LogicException("{$label} must implement Throwable");
        }

        return $obj;
    }

    public static function objectImplementsThrowable(ObjectEntry $obj): bool
    {
        $lc = strtolower($obj->class->name);
        if (self::CLASS_EXCEPTION === $lc || self::CLASS_ERROR === $lc) {
            return true;
        }

        return in_array(self::CLASS_THROWABLE, $obj->class->interfaces, true);
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
        $receiver->getProperty(self::PROP_LINE)->int(self::throwSiteLine($frame));
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
}
