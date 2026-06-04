<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\SuperglobalNames;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __compiler_is_superglobal_name (issue #5391, #1056).
 *
 * Mirrors ext/standard/SuperglobalNames and lib/Web/Superglobals::isSuperglobalName().
 */
final class SuperglobalNameRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_is_superglobal_name');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_is_superglobal_name', $ft);
        self::implementIsSuperglobalName($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementIsSuperglobalName(Context $context, Value $fn): void
    {
        self::ensureMemcmp($context);

        $entry = $fn->appendBasicBlock('sg_name_entry');
        $context->builder->positionAtEnd($entry);

        $name = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $zeroI64 = $i64->constInt(0, false);
        $falseI1 = $i1->constInt(0, false);

        $nullName = $context->builder->icmp(Builder::INT_EQ, $name, $name->typeOf()->constNull());
        $nullBb = $fn->appendBasicBlock('sg_name_null');
        $checkBb = $fn->appendBasicBlock('sg_name_check');
        $context->builder->branchIf($nullName, $nullBb, $checkBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($zeroI64);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($checkBb);
        $hit = $falseI1;
        foreach (SuperglobalNames::ALL as $idx => $literal) {
            $match = self::identicalToAsciiLiteral($context, $fn, $name, $literal, $idx);
            $hit = $context->builder->or($hit, $match);
        }
        $context->builder->returnValue(
            $context->builder->zExt($hit, $i64)
        );
        $context->builder->clearInsertionPosition();
    }

    private static function identicalToAsciiLiteral(
        Context $context,
        Value $fn,
        Value $name,
        string $literal,
        int $idx
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $map['length'])
        );
        $litLen = $context->getTypeFromString('int64')->constInt(\strlen($literal), false);
        $lenEq = $context->builder->icmp(Builder::INT_EQ, $nameLen, $litLen);
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);

        $suffix = 'i'.$idx;
        $lenOk = $fn->appendBasicBlock('sg_lit_len_ok_'.$suffix);
        $lenBad = $fn->appendBasicBlock('sg_lit_len_bad_'.$suffix);
        $merge = $fn->appendBasicBlock('sg_lit_done_'.$suffix);
        $context->builder->branchIf($lenEq, $lenOk, $lenBad);

        $context->builder->positionAtEnd($lenBad);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($lenOk);
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->zExt($nameLen, $sizeT);
        $i8p = $context->getTypeFromString('int8*');
        $litGlobal = $context->constantFromString($literal);
        $litPtr = $context->builder->pointerCast($litGlobal, $i8p);
        $nameValPtr = $context->builder->structGep($name, $map['value']);
        $namePtr = $context->builder->pointerCast($nameValPtr, $i8p);
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $namePtr,
            $litPtr,
            $len
        );
        $strEq = $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $cmp->typeOf()->constInt(0, false)
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($falseVal, $lenBad);
        $phi->addIncoming($strEq, $lenOk);

        return $phi;
    }

    private static function ensureMemcmp(Context $context): void
    {
        try {
            $context->lookupFunction('memcmp');
        } catch (\Throwable $e) {
            $sizeT = $context->getTypeFromString('size_t');
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p, $sizeT);
            $fn = $context->module->addFunction('memcmp', $ft);
            $context->registerFunction('memcmp', $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_is_superglobal_name');
        if (null === $fn) {
            throw new \LogicException('__compiler_is_superglobal_name missing after SuperglobalNameRuntime LLVM implement');
        }
        $context->registerFunction('__compiler_is_superglobal_name', $fn);
    }
}
