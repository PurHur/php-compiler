<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringCaseCompare;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\LazyGhostTraitSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for trait_exists() (issue #1371, #2312). */
final class JitTraitExists
{
    public static function invoke(Context $context, JITVariable $nameArg): Value
    {
        $literal = JitStringArg::compileTimeLiteral($nameArg);
        if (null !== $literal) {
            return ReflectionBuiltinHelper::traitExistsLiteral($context, $literal);
        }

        $nameStr = JitStringArg::lower($context, $nameArg, 'trait_exists() trait name');
        StringCaseCompare::ensureStrcasecmpLinked($context);
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $exists = $i1->constInt(0, false);
        $strcasecmpFn = $context->lookupFunction('strcasecmp');
        $nameData = JitClassExists::stringDataPtr($context, $nameStr);

        $candidates = array_keys($context->type->object->traitClassLowerNames());
        if (null !== $context->runtime->vmContext) {
            foreach ($context->runtime->vmContext->classes as $lc => $entry) {
                if ($entry->isTrait && !LazyGhostTraitSupport::isLazyGhostTrait($lc)) {
                    $candidates[] = $lc;
                }
            }
            $candidates = array_values(array_unique($candidates));
        }

        foreach ($candidates as $lc) {
            if (LazyGhostTraitSupport::isLazyGhostTrait($lc)) {
                continue;
            }
            $candidate = $context->builder->load($context->constantStringFromString($lc));
            $candidateData = JitClassExists::stringDataPtr($context, $candidate);
            $cmp = $context->builder->call($strcasecmpFn, $nameData, $candidateData);
            $match = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $exists = $context->builder->or($exists, $match);
        }

        return $exists;
    }
}
