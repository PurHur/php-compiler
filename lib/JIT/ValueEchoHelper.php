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
use PHPCompiler\JIT\IncDecResourceProvenance;
use PHPCfg\Operand;
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
    public static function echoNativeLong(
        Context $context,
        Value $longVal,
        ?Operand $sourceOperand = null
    ): void
    {
        Builtin\StringDir::ensureLinked($context);
        $tag = 'enl'.(string) ++self::$seq;
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->zExt($longVal, $i64);
        if (IncDecResourceProvenance::cannotBeResourceForString($sourceOperand)) {
            $context->builder->call(
                $context->lookupFunction('__phpc_ob_echo_ll'),
                $handle
            );

            return;
        }
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
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
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

        // Value-boxed objects (script globals) have no compile-time class hint. Detect
        // BcMath\Number by runtime class_id before the generic "Object" / Error path (#24683).
        $done = BasicBlockHelper::append($context, 'echo_object_done');
        $fallback = BasicBlockHelper::append($context, 'echo_object_fallback');
        $numberProxy = null;
        $numberId = 0;
        if (\PHPCompiler\CompilerVersion::supportsBcmath()) {
            $numberLc = 'bcmath\\number';
            $object = $context->type->object;
            $numberId = $object->lookup($numberLc);
            if (MagicMethodDispatch::hasInstanceMethod($object, $numberId, '__tostring')) {
                $numberProxy = MagicMethodDispatch::resolveInstanceMethodProxy(
                    $context,
                    $numberLc,
                    '__tostring'
                );
            }
        }
        if (null !== $numberProxy) {
            $objPtr = $context->helper->loadValue($objectVar);
            $map = $context->structFieldMap['__object__'];
            $classIdVal = $context->builder->load($context->builder->structGep($objPtr, $map['class_id']));
            $isNumber = $context->builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $classIdVal,
                $context->getTypeFromString('int64')->constInt($numberId, false)
            );
            $yes = BasicBlockHelper::append($context, 'echo_bcmath_number_yes');
            $context->builder->branchIf($isNumber, $yes, $fallback);
            $context->builder->positionAtEnd($yes);
            $toCall = $context->resolveFunctionProxy($numberProxy);
            $raw = $toCall->call($context, $objectVar);
            $strPtr = (new \PHPCompiler\ext\standard\strval())->valueToString(
                $context,
                JitValueBox::coerceToValuePtrForStore($context, $raw)
            );
            self::echoStringVariable(
                $context,
                new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $strPtr)
            );
            $context->builder->branch($done);
        } else {
            $context->builder->branch($fallback);
        }

        $context->builder->positionAtEnd($fallback);
        $classHint = $classHint ?? '';
        $classHint = ltrim((string) $classHint, '\\');
        if ('' !== $classHint && 'object' !== strtolower($classHint)) {
            Builtin\ErrorRaise::ensureLinked($context);
            Builtin\ErrorRaise::emitRaise(
                $context,
                ValueEchoSupport::objectToStringErrorMessage($classHint)
            );
            $context->builder->branch($done);
        } else {
            self::echoLiteral($context, ValueEchoSupport::OBJECT_FALLBACK_LABEL);
            $context->builder->branch($done);
        }
        $context->builder->positionAtEnd($done);
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
