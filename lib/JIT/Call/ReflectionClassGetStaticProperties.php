<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GetClassVarsRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionClass::getStaticProperties() — JIT/AOT (#34118, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod returned NULL. Materialize
 * compile-unit static defaults via {@see GetClassVarsRuntime} (peer
 * getDefaultProperties #34091) dispatched by ReflectionClass name → class_id.
 *
 * php-src: zim_ReflectionClass_getStaticProperties
 */
final class ReflectionClassGetStaticProperties implements Call
{
    private static int $blockSeq = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionClass::getStaticProperties',
                    0,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_getstaticproperties_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $classIdVal = ReflectionClassNewLazyProxy::loadClassIdFromLazyFactoryArg(
            $context,
            $args[0]
        );

        return $this->dispatchByClassId($context, $classIdVal);
    }

    private function dispatchByClassId(Context $context, Value $classId): Value
    {
        $object = $context->type->object;
        /** @var list<int> $ids */
        $ids = [];
        foreach ($object->allClassNamesById() as $id => $name) {
            if (!$object->hasUserDeclaredClass($name)) {
                continue;
            }
            $ids[] = (int) $id;
        }

        if ([] === $ids) {
            return GetClassVarsRuntime::emitEmptyDefaultProperties($context);
        }

        $tag = 'gsp'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'refl_gsp_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'refl_gsp_undef_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__value__*')
        );

        $n = \count($ids);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'refl_gsp_check_'.$tag.'_'.$i);
        }

        foreach ($ids as $i => $id) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $expected = $context->constantFromInteger($id, 'int64');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $onMatch = BasicBlockHelper::append($context, 'refl_gsp_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $undef;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $raw = GetClassVarsRuntime::emitStaticPropertiesForClassId($context, $id);
            $context->builder->store(
                JitValueBox::coerceToValuePtrForStore($context, $raw),
                $resultSlot
            );
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($undef);
        $empty = GetClassVarsRuntime::emitEmptyDefaultProperties($context);
        $context->builder->store(
            JitValueBox::coerceToValuePtrForStore($context, $empty),
            $resultSlot
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }
}
