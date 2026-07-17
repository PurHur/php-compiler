<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for dns_get_record() via compile-time VmDns materializer (#6392). */
final class JitDnsGetRecord
{
    private static int $blockSerial = 0;

    public static function invoke(
        Context $context,
        JITVariable $hostnameArg,
        ?JITVariable $typeArg,
        ?JITVariable $authnsArg,
        ?JITVariable $addtlArg,
    ): Value {
        $literal = JitStringArg::compileTimeLiteral($hostnameArg);
        if (null === $literal) {
            throw new \LogicException(
                'dns_get_record() requires compile-time string hostname for JIT/AOT in this build'
            );
        }

        return self::invokeLiteral($context, $literal, $typeArg, $authnsArg, $addtlArg);
    }

    public static function invokeLiteral(
        Context $context,
        string $literal,
        ?JITVariable $typeArg,
        ?JITVariable $authnsArg,
        ?JITVariable $addtlArg,
    ): Value {
        $typeInt = StdlibConstants::DNS_A;
        if (null !== $typeArg) {
            $typeInt = self::compileTimeInt($context, $typeArg)
                ?? throw new \LogicException('dns_get_record() requires compile-time int type for JIT/AOT in this build');
        }

        VmDns::validateDnsGetRecordType($typeInt);
        $materialized = JitDnsGetRecordMaterializer::materialize($context, $literal, $typeInt);

        if (null !== $authnsArg) {
            $authnsPtr = JitValueBox::valuePtrFromVariable($context, $authnsArg);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $authnsPtr,
                $materialized['authns']
            );
        }
        if (null !== $addtlArg) {
            $addtlPtr = JitValueBox::valuePtrFromVariable($context, $addtlArg);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $addtlPtr,
                $materialized['addtl']
            );
        }

        return self::boxedArrayOrFalse($context, $materialized['records'], $materialized['ok']);
    }

    private static function compileTimeInt(Context $context, JITVariable $var): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $var->type && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($var->value->value);
            }
        }

        return null;
    }

    private static function boxedArrayOrFalse(Context $context, Value $listHt, bool $ok): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'dns_get_record_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'dns_get_record_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'dns_get_record_done_'.$id);

        if (!$ok) {
            $context->builder->branch($failBlock);
        } else {
            $context->builder->branch($okBlock);
        }

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $listHt
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
