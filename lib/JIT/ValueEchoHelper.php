<?php

declare(strict_types=1);

/**
 * Echo/print for boxed __value__ variables in JIT (native LLVM).
 *
 * SSOT: {@see \PHPCompiler\VM\ValueEchoSupport}
 * Dispatch: {@see \PHPCompiler\JIT\Builtin\ValueEchoRuntime}
 */

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ValueEchoRuntime;
use PHPCompiler\VM\ValueEchoSupport;
use PHPLLVM\Value;

final class ValueEchoHelper
{
    private static int $seq = 0;

    public static function echoLiteral(Context $context, string $literal): void
    {
        $charPtr = $context->getTypeFromString('char*');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_cstr'),
            $context->builder->pointerCast(
                $context->constantFromString($literal),
                $charPtr
            )
        );
    }

    /**
     * Echo a native long, formatting stream/dir resources like Zend (ext/standard, #4740).
     */
    public static function echoNativeLong(Context $context, Value $longVal): void
    {
        Builtin\StringDir::ensureLinked($context);
        $tag = 'enl'.(string) ++self::$seq;
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->zExt($longVal, $i64);
        $isRes = JitValueCompare::nativeLongIsResource($context, $handle);

        $plainBlock = BasicBlockHelper::append($context, 'echo_native_long_plain_'.$tag);
        $resBlock = BasicBlockHelper::append($context, 'echo_native_long_res_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'echo_native_long_done_'.$tag);

        $context->builder->branchIf($isRes, $resBlock, $plainBlock);

        $context->builder->positionAtEnd($plainBlock);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_ll'),
            $handle
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($resBlock);
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $bufSize = $sizeT->constInt(32, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString(ValueEchoSupport::RESOURCE_FORMAT),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $handle
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_substr'),
            $bufChar,
            $context->builder->zExt($written, $sizeT)
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    /**
     * Echo an object via __toString when defined; otherwise raise Error (Zend cast-to-string, #4740).
     */
    public static function echoObjectVariable(Context $context, Variable $objectVar, ?string $classHint = null): void
    {
        $asString = MagicMethodDispatch::coerceObjectToString($context, $objectVar, $classHint);
        if (null !== $asString) {
            self::echoStringVariable($context, $asString);

            return;
        }
        $classHint = $classHint ?? $objectVar->type?->userType ?? '';
        $classHint = ltrim((string) $classHint, '\\');
        if ('' !== $classHint && 'object' !== strtolower($classHint)) {
            Builtin\ErrorRaise::ensureLinked($context);
            Builtin\ErrorRaise::emitRaise(
                $context,
                ValueEchoSupport::objectToStringErrorMessage($classHint)
            );

            return;
        }
        self::echoLiteral($context, ValueEchoSupport::OBJECT_FALLBACK_LABEL);
    }

    public static function echoStringVariable(Context $context, Variable $stringVar): void
    {
        $argValue = $context->helper->loadValue($stringVar);
        $offset = $context->structFieldIndex($argValue, 'length');
        $__str__length = $context->builder->load(
            $context->builder->structGep($argValue, $offset)
        );
        $offset = $context->structFieldIndex($argValue, 'value');
        $__str__value = $context->builder->structGep($argValue, $offset);
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_substr'),
            $__str__value,
            $context->builder->zExt($__str__length, $sizeT)
        );
    }

    public static function echo(Context $context, Value $valuePtr): void
    {
        ValueEchoRuntime::emitValue($context, $valuePtr);
    }
}
