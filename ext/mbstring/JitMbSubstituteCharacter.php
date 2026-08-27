<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbSubstituteCharacterRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_substitute_character() (#13100, #29919, #35263 runtime setter).
 *
 * Compile-time fold for null/omitted getter; runtime get/set via NestedJIT
 * {@see MbSubstituteCharacterJitHelper} + module global (peer {@see JitMbLanguage}).
 */
final class JitMbSubstituteCharacter
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_substitute_character() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (0 === $argc
            || (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
        ) {
            return self::lowerGet($context);
        }

        $stringLit = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $stringLit) {
            $packed = MbSubstituteCharacterJitHelper::canonicalizeStringArgv($stringLit);

            return self::lowerSetPacked($context, $packed);
        }

        if (
            null !== $args[0]->compileTimeLong
            && null !== $args[0]->value
            && \PHPLLVM\Value::KIND_CONSTANT_INT === $args[0]->value->getKind()
            && !JitValueBox::isValueOperand($args[0])
        ) {
            $packed = MbSubstituteCharacterJitHelper::canonicalizeLongArgv((int) $args[0]->compileTimeLong);

            return self::lowerSetPacked($context, $packed);
        }

        return self::lowerSetRuntime($context, $args[0]);
    }

    private static function lowerGet(Context $context): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbSubstituteCharacterRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_substitute_character_get');

        $g = MbSubstituteCharacterRuntime::substCodeGlobal($context);
        $code = $context->builder->load($g);
        $i64 = $context->getTypeFromString('int64');

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $doneBb = BasicBlockHelper::append($context, 'mb_substitute_character_get_done');

        $named = [
            MbSubstituteCharacterJitHelper::CODE_NONE => 'none',
            MbSubstituteCharacterJitHelper::CODE_LONG => 'long',
            MbSubstituteCharacterJitHelper::CODE_ENTITY => 'entity',
        ];
        $next = null;
        foreach ($named as $codeVal => $name) {
            $matchBb = BasicBlockHelper::append($context, 'mb_substitute_character_get_'.$name);
            $elseBb = BasicBlockHelper::append($context, 'mb_substitute_character_get_not_'.$name);
            if (null !== $next) {
                $context->builder->positionAtEnd($next);
            }
            $isMatch = $context->builder->icmp(
                Builder::INT_EQ,
                $code,
                $i64->constInt($codeVal, true)
            );
            $context->builder->branchIf($isMatch, $matchBb, $elseBb);

            $context->builder->positionAtEnd($matchBb);
            self::writeStringConstant($context, $ptr, $name);
            $context->builder->branch($doneBb);

            $next = $elseBb;
        }

        // >= 0 → MODE_CHAR codepoint (default 63).
        $context->builder->positionAtEnd($next);
        JitValueBox::writeLong($context, $slot, $code);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }

    private static function lowerSetPacked(Context $context, int $packed): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbSubstituteCharacterRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_substitute_character_set_lit');

        $i64 = $context->getTypeFromString('int64');
        $g = MbSubstituteCharacterRuntime::substCodeGlobal($context);
        $context->builder->store($i64->constInt($packed, true), $g);

        return $context->constantFromBool(true);
    }

    private static function lowerSetRuntime(Context $context, JITVariable $arg): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbSubstituteCharacterRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_substitute_character_set');

        $i64 = $context->getTypeFromString('int64');
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $long = JitLongArg::lower($context, $arg, 'mb_substitute_character');
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                MbSubstituteCharacterRuntime::canonicalizeLongHelper($context),
                [$long]
            );
            TryCatchHelper::emitCheckPendingThrowAfterCall($context);
            $packed = JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i64);
        } elseif (
            JITVariable::TYPE_STRING === $arg->type
            || JitValueBox::isValueOperand($arg)
        ) {
            if (JitValueBox::isValueOperand($arg)) {
                $packed = self::lowerSetFromValueBox($context, $arg);
            } else {
                $str = JitStringBuiltinArg::lower(
                    $context,
                    $arg,
                    'mb_substitute_character',
                    0,
                    'substitute_character'
                );
                $raw = JitNestedHelperCoerce::callHelper(
                    $context,
                    MbSubstituteCharacterRuntime::canonicalizeStringHelper($context),
                    [$str]
                );
                TryCatchHelper::emitCheckPendingThrowAfterCall($context);
                $packed = JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i64);
            }
        } else {
            throw new \LogicException(
                'mb_substitute_character() JIT setter expects int|string|null in this compiler build'
            );
        }

        $g = MbSubstituteCharacterRuntime::substCodeGlobal($context);
        $context->builder->store($packed, $g);

        return $context->constantFromBool(true);
    }

    private static function lowerSetFromValueBox(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $intBb = BasicBlockHelper::append($context, 'mb_subst_vbox_int');
        $strBb = BasicBlockHelper::append($context, 'mb_subst_vbox_str');
        $doneBb = BasicBlockHelper::append($context, 'mb_subst_vbox_done');

        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(VmVariable::TYPE_INTEGER, false)
        );
        $context->builder->branchIf($isInt, $intBb, $strBb);

        $context->builder->positionAtEnd($intBb);
        $long = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $rawInt = JitNestedHelperCoerce::callHelper(
            $context,
            MbSubstituteCharacterRuntime::canonicalizeLongHelper($context),
            [$long]
        );
        TryCatchHelper::emitCheckPendingThrowAfterCall($context);
        $packedInt = JitNestedHelperCoerce::extractLongFromHelperResult($context, $rawInt, $i64);
        $intEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($strBb);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $rawStr = JitNestedHelperCoerce::callHelper(
            $context,
            MbSubstituteCharacterRuntime::canonicalizeStringHelper($context),
            [$str]
        );
        TryCatchHelper::emitCheckPendingThrowAfterCall($context);
        $packedStr = JitNestedHelperCoerce::extractLongFromHelperResult($context, $rawStr, $i64);
        $strEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($packedInt, $intEnd);
        $phi->addIncoming($packedStr, $strEnd);

        return $phi;
    }

    private static function writeStringConstant(Context $context, Value $valuePtr, string $name): void
    {
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($name))
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $valuePtr,
            $owned
        );
    }
}
