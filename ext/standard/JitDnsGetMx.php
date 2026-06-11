<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for dns_get_mx() via compile-time VmDns materializer (#4125). */
final class JitDnsGetMx
{
    public static function invoke(
        Context $context,
        JITVariable $hostnameArg,
        JITVariable $hostsArg,
        ?JITVariable $weightsArg
    ): Value {
        $literal = JitStringArg::compileTimeLiteral($hostnameArg);
        if (null === $literal) {
            throw new \LogicException('dns_get_mx() requires compile-time string hostname for JIT/AOT in this build');
        }

        $materialized = JitDnsGetMxMaterializer::materialize($context, $literal);

        $hostsPtr = JitValueBox::valuePtrFromVariable($context, $hostsArg);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $hostsPtr,
            $materialized['hosts']
        );

        if (null !== $weightsArg) {
            $weightsPtr = JitValueBox::valuePtrFromVariable($context, $weightsArg);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $weightsPtr,
                $materialized['weights']
            );
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool(
            $context,
            $slot,
            $i1->constInt($materialized['ok'] ? 1 : 0, false)
        );

        return $ptr;
    }
}
