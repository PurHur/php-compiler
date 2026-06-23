<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\BoundMethodCallableHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LateStaticBindingHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for forward_static_call() / forward_static_call_array() (#3197, #6853).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(forward_static_call*)
 */
final class JitForwardStaticCall
{
    private static int $blockSeq = 0;

    /**
     * @param list<JITVariable> $extraArgs
     */
    public static function invoke(Context $context, JITVariable $callable, array $extraArgs, string $builtinName): Value
    {
        $block = self::requireClassScope($context, $builtinName);
        $methodLc = self::parseMethodLc($context, $callable, $builtinName);
        $candidates = self::buildStaticMethodCandidatesByClassId($context, $methodLc);
        if ([] === $candidates) {
            throw new \LogicException(
                "Call to undefined static method via {$builtinName}() in this compiler build"
            );
        }

        return self::dispatchFromCalledScope($context, $block, $candidates, $extraArgs);
    }

    public static function invokeArray(
        Context $context,
        JITVariable $callable,
        JITVariable $params,
        string $builtinName
    ): Value {
        self::emitRequireEmptyParamsArray($context, $params, $builtinName);
        $methodLc = self::parseMethodLc($context, $callable, $builtinName);
        $block = $context->jitEnclosingBlock;
        $hasClassScope = $block instanceof Block
            && null !== $block->func
            && null !== $block->func->class;
        if (!$hasClassScope) {
            $explicitClass = self::resolveExplicitClassName($context, $callable);
            if (null === $explicitClass) {
                ErrorRaise::registerDeclarations($context);
                ErrorRaise::ensureLinked($context);
                ErrorRaise::emitRaise($context, "Cannot call {$builtinName}() when no class scope is active");
                $context->builder->call($context->lookupFunction('abort'));

                return $context->getTypeFromString('__value__*')->constNull();
            }

            return self::dispatchExplicitClass($context, $explicitClass, $methodLc, []);
        }
        $candidates = self::buildStaticMethodCandidatesByClassId($context, $methodLc);
        if ([] === $candidates) {
            throw new \LogicException(
                "Call to undefined static method via {$builtinName}() in this compiler build"
            );
        }

        return self::dispatchFromCalledScope($context, $block, $candidates, []);
    }

    private static function emitRequireEmptyParamsArray(
        Context $context,
        JITVariable $params,
        string $builtinName
    ): void {
        if (
            !ArrayBuiltinHelper::isNativeArray($params->type)
            && JITVariable::TYPE_HASHTABLE !== $params->type
        ) {
            throw new \LogicException(
                "{$builtinName}() argument #2 (\$parameters) must be of type array in this compiler build"
            );
        }
        $ht = ArrayBuiltinHelper::loadHashTable($context, $params);
        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $isEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $count,
            $count->typeOf()->constInt(0, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'fsc_params_ok');
        $failBlock = BasicBlockHelper::append($context, 'fsc_params_fail');
        $context->builder->branchIf($isEmpty, $okBlock, $failBlock);
        $context->builder->positionAtEnd($failBlock);
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise(
            $context,
            "{$builtinName}() non-empty parameter arrays are not supported in JIT in this compiler build"
        );
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }

    /**
     * @param array<int, Call> $candidatesByClassId
     * @param list<JITVariable> $extraArgs
     */
    private static function dispatchFromCalledScope(
        Context $context,
        Block $block,
        array $candidatesByClassId,
        array $extraArgs
    ): Value {
        $objectType = $context->type->object;
        if (LateStaticBindingHelper::useRuntimeLateStatic($context)) {
            $classId = LateStaticBindingHelper::emitEffectiveLateStaticClassId($objectType, $block);
        } else {
            $called = $context->scope->calledClassName;
            if ('' === $called) {
                $called = $block->func->class->value;
            }
            $classId = $context->constantFromInteger($objectType->lookup($called), 'int64');
        }

        return self::dispatchByClassId($context, $classId, $candidatesByClassId, $extraArgs);
    }

    /**
     * @param array<int, Call> $candidatesByClassId
     * @param list<JITVariable> $extraArgs
     */
    private static function dispatchByClassId(
        Context $context,
        Value $classId,
        array $candidatesByClassId,
        array $extraArgs
    ): Value {
        $tag = 'fsc'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'fsc_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'fsc_undef_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $zero = $context->getTypeFromString('__value__*')->constNull();
        $context->builder->store($zero, $resultSlot);

        $ids = array_keys($candidatesByClassId);
        $n = \count($ids);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'fsc_check_'.$tag.'_'.$i);
        }

        foreach ($ids as $i => $id) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $expected = $context->constantFromInteger($id, 'int64');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $onMatch = BasicBlockHelper::append($context, 'fsc_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $undef;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            if (LateStaticBindingHelper::useRuntimeLateStatic($context)) {
                LateStaticBindingHelper::emitStoreClassId(
                    $context,
                    $context->constantFromInteger($id, 'int64')
                );
            }
            $proxy = $candidatesByClassId[$id];
            assert($proxy instanceof Call);
            $raw = $proxy->call($context, ...$extraArgs);
            $context->builder->store(
                JitValueBox::coerceToValuePtrForStore($context, $raw),
                $resultSlot
            );
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($undef);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * @return array<int, Call>
     */
    private static function buildStaticMethodCandidatesByClassId(Context $context, string $methodLc): array
    {
        $methodLc = strtolower($methodLc);
        $candidates = [];
        foreach ($context->type->object->allClassNamesById() as $classId => $className) {
            $proxyName = self::resolveStaticProxyForClass($context, strtolower(ltrim($className, '\\')), $methodLc);
            if (null === $proxyName || !$context->functionIsRegistered($proxyName)) {
                continue;
            }
            $candidates[$classId] = $context->resolveFunctionProxy($proxyName);
        }

        return $candidates;
    }

    private static function resolveStaticProxyForClass(Context $context, string $classLc, string $methodLc): ?string
    {
        $visited = [];
        $current = $classLc;
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $proxy = $current.'::'.$methodLc;
            if ($context->functionIsRegistered($proxy)) {
                return $proxy;
            }
            $parent = $context->type->object->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return null;
    }

    private static function parseMethodLc(Context $context, JITVariable $callable, string $builtinName): string
    {
        $literal = JitStringArg::compileTimeLiteral($callable);
        if (null !== $literal) {
            return self::methodLcFromStringCallable($literal, $builtinName);
        }

        if (null !== $callable->compileTimeString && str_contains($callable->compileTimeString, '::')) {
            return self::methodLcFromStringCallable($callable->compileTimeString, $builtinName);
        }

        $block = $context->jitEnclosingBlock;
        if ($block instanceof Block && isset($context->scope->argOperands[0])) {
            $slot = $block->slotForOperand($context->scope->argOperands[0]);
            if (null !== $slot) {
                $methodLc = BoundMethodCallableHelper::resolveMethodLcFromCalleeSlot($block, $slot);
                if (null !== $methodLc && '' !== $methodLc) {
                    return $methodLc;
                }
            }
        }

        throw new \LogicException(
            "{$builtinName}() callback must be a compile-time string or [class, method] array in JIT in this compiler build"
        );
    }

    private static function methodLcFromStringCallable(string $literal, string $builtinName): string
    {
        if (!str_contains($literal, '::')) {
            throw new \LogicException(
                "{$builtinName}() string callable must be Class::method"
            );
        }
        [, $method] = explode('::', $literal, 2);
        if ('' === $method) {
            throw new \LogicException(
                "{$builtinName}() string callable must name a method"
            );
        }

        return strtolower($method);
    }

    private static function resolveExplicitClassName(Context $context, JITVariable $callable): ?string
    {
        $literal = JitStringArg::compileTimeLiteral($callable);
        if (null !== $literal && str_contains($literal, '::')) {
            [$class] = explode('::', $literal, 2);

            return '' !== $class ? $class : null;
        }

        $block = $context->jitEnclosingBlock;
        if ($block instanceof Block && isset($context->scope->argOperands[0])) {
            $slot = $block->slotForOperand($context->scope->argOperands[0]);
            if (null !== $slot) {
                return BoundMethodCallableHelper::resolveBoundMethodReceiverClassName($block, $slot);
            }
        }

        return null;
    }

    /**
     * @param list<JITVariable> $extraArgs
     */
    private static function dispatchExplicitClass(
        Context $context,
        string $className,
        string $methodLc,
        array $extraArgs
    ): Value {
        $classLc = strtolower(ltrim($className, '\\'));
        $proxyName = self::resolveStaticProxyForClass($context, $classLc, $methodLc);
        if (null === $proxyName || !$context->functionIsRegistered($proxyName)) {
            throw new \LogicException(
                "Call to undefined static method {$className}::{$methodLc}() in this compiler build"
            );
        }
        $classId = $context->type->object->lookup($className);
        $candidates = [$classId => $context->resolveFunctionProxy($proxyName)];

        return self::dispatchByClassId(
            $context,
            $context->constantFromInteger($classId, 'int64'),
            $candidates,
            $extraArgs
        );
    }

    private static function requireClassScope(Context $context, string $builtinName): Block
    {
        $block = $context->jitEnclosingBlock;
        if (!$block instanceof Block || null === $block->func || null === $block->func->class) {
            ErrorRaise::registerDeclarations($context);
            ErrorRaise::ensureLinked($context);
            ErrorRaise::emitRaise($context, "Cannot call {$builtinName}() when no class scope is active");
            $context->builder->call($context->lookupFunction('abort'));
        }

        return $block;
    }
}
