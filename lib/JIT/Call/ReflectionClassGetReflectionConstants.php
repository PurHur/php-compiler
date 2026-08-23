<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionClassGetReflectionConstantsRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionClass::getReflectionConstants() — JIT/AOT (#34119, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod returned NULL. Materialize
 * ReflectionClassConstant list via {@see ReflectionClassGetReflectionConstantsRuntime}
 * (peer getConstants #34109 / getProperties #34113).
 *
 * php-src: zim_ReflectionClass_getReflectionConstants
 */
final class ReflectionClassGetReflectionConstants implements Call
{
    private static int $blockSeq = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        $userArgCount = \count($args) - 1;
        // php-src: optional ?int $filter — at most 1 (#31033 / #6662)
        if ($userArgCount < 0 || $userArgCount > 1) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'ReflectionClass::getReflectionConstants() expects at most 1 argument, '.$userArgCount.' given'
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_getreflectionconstants_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $filter = 0;
        if (1 === $userArgCount) {
            $filterArg = $args[1];
            if (Variable::TYPE_NULL === $filterArg->type || !empty($filterArg->isNullConstant)) {
                $filter = 0;
            } elseif (null !== $filterArg->compileTimeLong) {
                $filter = (int) $filterArg->compileTimeLong;
            }
        }

        $classIdVal = ReflectionClassNewLazyProxy::loadClassIdFromLazyFactoryArg(
            $context,
            $args[0]
        );

        return $this->dispatchByClassId($context, $classIdVal, $filter);
    }

    private function dispatchByClassId(Context $context, Value $classId, int $filter): Value
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
            return ReflectionClassGetReflectionConstantsRuntime::emitEmpty($context);
        }

        $tag = 'grc'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'refl_grc_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'refl_grc_undef_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__value__*')
        );

        $n = \count($ids);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'refl_grc_check_'.$tag.'_'.$i);
        }

        foreach ($ids as $i => $id) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $expected = $context->constantFromInteger($id, 'int64');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $onMatch = BasicBlockHelper::append($context, 'refl_grc_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $undef;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $raw = ReflectionClassGetReflectionConstantsRuntime::emitForClassId($context, $id, $filter);
            $context->builder->store(
                JitValueBox::coerceToValuePtrForStore($context, $raw),
                $resultSlot
            );
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($undef);
        $empty = ReflectionClassGetReflectionConstantsRuntime::emitEmpty($context);
        $context->builder->store(
            JitValueBox::coerceToValuePtrForStore($context, $empty),
            $resultSlot
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }
}
