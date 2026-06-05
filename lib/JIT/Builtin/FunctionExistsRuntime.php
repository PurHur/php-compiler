<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\BuiltinRegistry;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of function_exists() builtin registry (issue #5390, #1216).
 *
 * Replaces lib/AOT/runtime/function_exists.c. php-src: ext/standard/basic_functions.c
 */
final class FunctionExistsRuntime
{
    private static int $blockSuffix = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $strPtr);
        $probe = $context->module->getNamedFunction('__compiler_builtin_function_exists');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_builtin_function_exists', $ft);
        if ($fn->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_builtin_function_exists', $fn);

            return;
        }

        self::ensureLibc($context);
        self::implementLookup($context, $fn, BuiltinRegistry::sortedNames());
        $context->registerFunction('__compiler_builtin_function_exists', $fn);
    }

    /**
     * @param list<string> $names Sorted lowercase builtin names.
     */
    private static function implementLookup(Context $context, LlvmFunction $fn, array $names): void
    {
        $entry = $fn->appendBasicBlock('fe_entry');
        $context->builder->positionAtEnd($entry);

        $name = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $name, $nullStr);
        $nullBb = $fn->appendBasicBlock('fe_null');
        $missBb = $fn->appendBasicBlock('fe_miss');
        $hitBb = $fn->appendBasicBlock('fe_hit');
        $context->builder->branchIf($isNull, $nullBb, $missBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($i64->constInt(0, false));

        $context->builder->positionAtEnd($hitBb);
        $context->builder->returnValue($i64->constInt(1, false));

        $cursor = $missBb;
        foreach ($names as $literal) {
            $nextBb = $fn->appendBasicBlock('fe_try_'.(++self::$blockSuffix));
            $context->builder->positionAtEnd($cursor);
            $cmp = self::compareNameToLiteral($context, $fn, $name, $literal);
            $i32 = $context->getTypeFromString('int32');
            $isEq = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $context->builder->branchIf($isEq, $hitBb, $nextBb);
            $cursor = $nextBb;
        }

        $context->builder->positionAtEnd($cursor);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->clearInsertionPosition();
    }

    private static function compareNameToLiteral(
        Context $context,
        LlvmFunction $fn,
        Value $name,
        string $literal
    ): Value {
        $map = self::stringFieldMap($context);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $map['length'])
        );
        $litLen = $i64->constInt(\strlen($literal), false);

        $suffix = (string) ++self::$blockSuffix;
        $shortBb = $fn->appendBasicBlock('fe_cmp_short_'.$suffix);
        $longBb = $fn->appendBasicBlock('fe_cmp_long_'.$suffix);
        $memcmpBb = $fn->appendBasicBlock('fe_cmp_mem_'.$suffix);
        $doneBb = $fn->appendBasicBlock('fe_cmp_done_'.$suffix);

        $nameShorter = $context->builder->icmp(Builder::INT_SLT, $nameLen, $litLen);
        $nameLonger = $context->builder->icmp(Builder::INT_SGT, $nameLen, $litLen);
        $checkLongBb = $fn->appendBasicBlock('fe_cmp_check_long_'.$suffix);
        $context->builder->branchIf($nameShorter, $shortBb, $checkLongBb);

        $context->builder->positionAtEnd($checkLongBb);
        $context->builder->branchIf($nameLonger, $longBb, $memcmpBb);

        $context->builder->positionAtEnd($shortBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($longBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($memcmpBb);
        $nameData = $context->builder->structGep($name, $map['value']);
        $litCstr = $context->builder->pointerCast($context->constantFromString($literal), $i8p);
        $cmpLen = $context->builder->zext($nameLen, $sizeT);
        $memcmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $nameData,
            $litCstr,
            $cmpLen
        );
        $lt = $context->builder->icmp(Builder::INT_SLT, $memcmp, $i32->constInt(0, true));
        $gt = $context->builder->icmp(Builder::INT_SGT, $memcmp, $i32->constInt(0, true));
        $negOne = $i32->constInt(-1, true);
        $posOne = $i32->constInt(1, true);
        $cmpFromMem = $context->builder->select($lt, $negOne, $context->builder->select($gt, $posOne, $i32->constInt(0, true)));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $lenPhi = $context->builder->phi($i32);
        $lenPhi->addIncoming($i32->constInt(-1, true), $shortBb);
        $lenPhi->addIncoming($i32->constInt(1, true), $longBb);
        $lenPhi->addIncoming($cmpFromMem, $memcmpBb);

        return $lenPhi;
    }

    /** @return array{ref: int, length: int, value: int} */
    private static function stringFieldMap(Context $context): array
    {
        return $context->structFieldMap['__string__'] ?? ['ref' => 0, 'length' => 1, 'value' => 2];
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        self::ensureExternal(
            $context,
            'memcmp',
            $context->context->functionType($i32, false, $i8p, $i8p, $sizeT)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }
}
