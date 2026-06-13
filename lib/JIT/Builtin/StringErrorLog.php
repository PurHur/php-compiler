<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM error_log() stderr helper (php-src _php_error_log default branch; #3380).
 */
final class StringErrorLog
{
    private const RUNTIME_FUNCTION = '__compiler_error_log';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::RUNTIME_FUNCTION);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureGlobals($context);
        self::ensureLibc($context);

        $fn = $context->lookupFunction(self::RUNTIME_FUNCTION);
        self::implementErrorLog($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementErrorLog(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('el_entry');
        $context->builder->positionAtEnd($entry);

        $message = $fn->getParam(0);
        $msgLen = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $message
        );
        $msgCStr = self::stringToCString($context, $message, $msgLen, 'el_msg');
        self::emitStderrWrite($context, $msgCStr);
    }

    private static function emitStderrWrite(Context $context, Value $msgCStr): void
    {
        $i1 = $context->getTypeFromString('int1');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $stderrPtr = StringTriggerErrorJit::stderrFilePtr($context);
        $rc = $context->builder->call(
            $context->lookupFunction('fprintf'),
            $stderrPtr,
            $context->builder->pointerCast($context->constantFromString('%s\n'), $i8p),
            $msgCStr
        );
        $context->builder->returnValue(
            $context->builder->icmp(Builder::INT_SGE, $rc, $i32->constInt(0, false))
        );
    }

    private static function stringToCString(Context $context, Value $str, Value $len, string $prefix): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $one = $context->getTypeFromString('int64')->constInt(1, false);
        $bytes = $context->builder->structGep($str, $map['value']);
        $bufLen = $context->builder->add($len, $one);
        $buf = $context->builder->alloca($i8, $bufLen, $prefix);
        $cStr = $context->builder->pointerCast($buf, $i8p);
        $context->intrinsic->memcpy($cStr, $bytes, $len, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($cStr, $len)
        );

        return $cStr;
    }

    private static function ensureGlobals(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        if (null === $context->module->getNamedGlobal('stderr')) {
            $context->module->addGlobal($i8p, 'stderr');
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');

        foreach ([
            ['fprintf', $i32, [$i8p, $i8p, $i8p]],
            ['__string__strlen', $i64, [$strPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal(
                $context,
                $name,
                $context->context->functionType($ret, 'fprintf' === $name, ...$params)
            );
        }
    }

    private static function ensureExternal(Context $context, string $name, $fnType): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $fnType);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::RUNTIME_FUNCTION);
        if (null === $fn) {
            throw new \LogicException(self::RUNTIME_FUNCTION.' missing after error_log LLVM link');
        }
        $context->registerFunction(self::RUNTIME_FUNCTION, $fn);
    }
}
