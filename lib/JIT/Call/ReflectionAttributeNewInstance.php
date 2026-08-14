<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\AttributeNewInstanceRuntime;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** ReflectionAttribute::newInstance() — JIT/AOT (#3206, #4598). */
final class ReflectionAttributeNewInstance implements Call
{
    private static int $seq = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);
        AttributeNewInstanceRuntime::ensureLinked($context);
        if ([] === $args) {
            throw new \LogicException('ReflectionAttribute::newInstance() requires an object receiver');
        }
        // php-src: zim_ReflectionAttribute_newInstance — 0 user args (#30896)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'ReflectionAttribute::newInstance() expects exactly 0 arguments, %d given',
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_attr_newinstance_argc_cont');
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                \PHPCompiler\JIT\JitValueBox::pointer($context, $slot)
            );

            return $slot;
        }
        $attrObj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $nameVar = $context->type->object->propertyFetch($attrObj, 'ReflectionAttribute', 'name');
        $classId = AttributeNewInstanceRuntime::emitResolveClassId($context, $nameVar);

        $tag = 'rani'.(string) (++self::$seq);
        $merge = BasicBlockHelper::append($context, 'attr_newinstance_merge_'.$tag);
        $missing = BasicBlockHelper::append($context, 'attr_newinstance_missing_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $null = $context->getTypeFromString('__value__*')->constNull();
        $i64 = $context->getTypeFromString('int64');

        $isMissing = $context->builder->icmp(Builder::INT_SLT, $classId, $i64->constInt(0, true));
        $ok = BasicBlockHelper::append($context, 'attr_newinstance_ok_'.$tag);
        $context->builder->branchIf($isMissing, $missing, $ok);

        $context->builder->positionAtEnd($ok);
        $obj = $context->type->object->allocateForRuntimeClassId($classId);
        $thisVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $thisVar->addref();
        $ctorArg = AttributeNewInstanceRuntime::emitReadCtorArgFromAttrOwner($context, $attrObj);

        $ctorDone = BasicBlockHelper::append($context, 'attr_newinstance_ctor_done_'.$tag);
        /** @var array<int, array{lc: string, name: string}> $userIds */
        $userIds = [];
        foreach ($context->type->object->allClassNamesById() as $id => $displayName) {
            if (!$context->type->object->hasUserDeclaredClass($displayName)) {
                continue;
            }
            $userIds[(int) $id] = [
                'lc' => strtolower(ltrim($displayName, '\\')),
                'name' => ltrim($displayName, '\\'),
            ];
        }
        $ctorIds = array_keys($userIds);
        $ctorN = count($ctorIds);
        if ($ctorN > 0) {
            $ctorChecks = [];
            for ($i = 0; $i < $ctorN; ++$i) {
                $ctorChecks[$i] = 0 === $i
                    ? $context->builder->getInsertBlock()
                    : BasicBlockHelper::append($context, 'attr_newinstance_ctor_chk_'.$tag.'_'.$i);
            }
            foreach ($ctorIds as $i => $id) {
                $context->builder->positionAtEnd($ctorChecks[$i]);
                $expected = $context->constantFromInteger($id, 'int64');
                $isMatch = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
                $onMatch = BasicBlockHelper::append($context, 'attr_newinstance_ctor_'.$tag.'_'.$id);
                $onMiss = ($i < $ctorN - 1) ? $ctorChecks[$i + 1] : $ctorDone;
                $context->builder->branchIf($isMatch, $onMatch, $onMiss);
                $context->builder->positionAtEnd($onMatch);
                $proxyName = $userIds[$id]['lc'].'::__construct';
                if ($context->functionIsRegistered($proxyName)) {
                    $context->resolveFunctionProxy($proxyName)->call($context, $thisVar, $ctorArg);
                    AttributeNewInstanceRuntime::emitApplyConstructorPropertyArgs($context, $obj, $id, $ctorArg);
                    $context->builder->branch($ctorDone);
                } else {
                    // No ctor: argc>0 → Error (#29955); argc=0 succeeds like Zend.
                    $hasArgs = AttributeNewInstanceRuntime::emitArgsNonEmpty($context, $attrObj);
                    $noCtorErr = BasicBlockHelper::append($context, 'attr_newinstance_nocto_'.$tag.'_'.$id);
                    $noCtorOk = BasicBlockHelper::append($context, 'attr_newinstance_noctook_'.$tag.'_'.$id);
                    $context->builder->branchIf($hasArgs, $noCtorErr, $noCtorOk);
                    $context->builder->positionAtEnd($noCtorErr);
                    AttributeNewInstanceRuntime::emitNoCtorArgsError($context, $userIds[$id]['name']);
                    $context->builder->store($null, $resultSlot);
                    $context->builder->branch($merge);
                    $context->builder->positionAtEnd($noCtorOk);
                    $context->builder->branch($ctorDone);
                }
            }
        }
        $context->builder->positionAtEnd($ctorDone);
        ReflectionSetup::markConstructed($context, $obj);
        $context->builder->store(AttributeNewInstanceRuntime::boxObject($context, $obj), $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($missing);
        AttributeNewInstanceRuntime::emitMissingClassError($context);
        $context->builder->store($null, $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }
}
