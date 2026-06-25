<?php

declare(strict_types=1);

/**
 * Coerce native JIT scalars to {@see __string__*} for concatenation.
 */

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitNativeString
{
    private static int $coerceResumeSerial = 0;

    /** Reposition when ensureLinked cleared LLVM insertion before boxed strval (#1492). */
    public static function ensureInsertBlock(Context $context): void
    {
        if (null !== BasicBlockHelper::tryGetInsertBlock($context)) {
            return;
        }
        $fn = BasicBlockHelper::parentFunction($context);
        $resume = $fn->appendBasicBlock('jit_native_string_resume_'.(++self::$coerceResumeSerial));
        $context->builder->positionAtEnd($resume);
    }

    public static function coerce(Context $context, Variable $var): Variable
    {
        if (Variable::TYPE_STRING === $var->type) {
            return $var;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            $magic = MagicMethodDispatch::coerceObjectToString($context, $var);
            if (null !== $magic) {
                return $magic;
            }
            $classHint = ltrim((string) ($var->type?->userType ?? ''), '\\');
            if (
                '' !== $classHint
                && 'object' !== strtolower($classHint)
                && $context->type->object->isEnumClassLc(strtolower($classHint))
            ) {
                Builtin\ErrorRaise::ensureLinked($context);
                Builtin\ErrorRaise::emitRaise(
                    $context,
                    'Object of class '.$classHint.' could not be converted to string'
                );

                return new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    $context->builder->load($context->constantStringFromString(''))
                );
            }
            throw new \LogicException(
                'Cannot coerce JIT type '.Variable::getStringType($var->type).' to string for concat'
            );
        }
        if (Variable::TYPE_VALUE === $var->type) {
            self::ensureInsertBlock($context);

            return new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                (new \PHPCompiler\ext\standard\strval())->valueToString(
                    $context,
                    JitValueBox::valuePtrFromVariable($context, $var)
                )
            );
        }

        $value = $context->helper->loadValue($var);
        switch ($var->type) {
            case Variable::TYPE_NATIVE_LONG:
                return new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    JitResourceIdString::formatNativeLong($context, $value)
                );
            case Variable::TYPE_NATIVE_DOUBLE:
                return new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    self::format($context, $value, '%.14g')
                );
            case Variable::TYPE_NATIVE_BOOL:
                self::ensureInsertBlock($context);
                $trueBlock = BasicBlockHelper::append($context, 'coerce_bool_true');
                $falseBlock = BasicBlockHelper::append($context, 'coerce_bool_false');
                $endBlock = BasicBlockHelper::append($context, 'coerce_bool_end');
                $context->builder->branchIf($value, $trueBlock, $falseBlock);
                $context->builder->positionAtEnd($trueBlock);
                $trueStr = $context->builder->load($context->constantStringFromString('1'));
                $context->builder->branch($endBlock);
                $context->builder->positionAtEnd($falseBlock);
                $falseStr = $context->builder->load($context->constantStringFromString(''));
                $context->builder->branch($endBlock);
                $context->builder->positionAtEnd($endBlock);
                $phi = $context->builder->phi($trueStr->typeOf());
                $phi->addIncoming($trueStr, $trueBlock);
                $phi->addIncoming($falseStr, $falseBlock);

                return new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    $phi
                );
            default:
                throw new \LogicException(
                    'Cannot coerce JIT type '.Variable::getStringType($var->type).' to string for concat'
                );
        }
    }

    /** Decimal string for a packed-list index (array_merge numeric-string keys; #3607). */
    public static function formatIndexKey(Context $context, Value $indexI64): Value
    {
        $sizeT = $context->getTypeFromString('size_t');

        return self::format(
            $context,
            $context->builder->truncOrBitCast($indexI64, $sizeT),
            '%zu'
        );
    }

    private static function format(Context $context, Value $value, string $format): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $bufSize = $sizeT->constInt(64, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString($format),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $value
        );
        $len = $context->builder->zExt($written, $i64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
    }
}
