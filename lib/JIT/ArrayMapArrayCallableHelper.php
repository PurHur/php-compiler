<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall;
use PHPCompiler\VM\VmBoundMethodCallable;

/**
 * Resolve compile-time array callables for array_map() JIT/AOT (#36382 / #1154).
 *
 * Static `['Class','method']` and bound `[$this,'method']` (FastRoute processChunk).
 * php-src: ext/standard/array.c php_array_map(); Zend/zend_execute.c ZEND_INIT_DYNAMIC_CALL.
 */
final class ArrayMapArrayCallableHelper
{
    /**
     * @return array{0:string,1:string}|null class + method
     */
    public static function resolveStaticNames(Context $context, Variable $callback): ?array
    {
        $fromVar = ArrayMapCallbackPolicy::compileTimeStaticArrayCallableNames($callback);
        if (null !== $fromVar) {
            return $fromVar;
        }

        return self::resolveStaticNamesFromBlock($context);
    }

    /**
     * @return array{0:Variable,1:Call}|null receiver + invoke proxy
     */
    public static function resolveBoundMethodCall(Context $context, Variable $callback): ?array
    {
        $block = $context->jitCurrentBlock ?? $context->jitEnclosingBlock;
        $callbackOp = $context->scope->argOperands[0] ?? null;
        if (!$block instanceof Block || !($callbackOp instanceof Operand)) {
            return null;
        }
        $slot = $block->slotForOperand($callbackOp);
        if (null === $slot) {
            return null;
        }
        if (!BoundMethodCallableHelper::isBoundMethodArrayCallee($callbackOp, $callback)) {
            // Still try slot walk — TYPE_VALUE callbacks lose TYPE_ARRAY on the operand.
            $slots = VmBoundMethodCallable::resolveStaticArrayCallableSlots($block, $slot);
            if (null !== $slots) {
                return null; // static form — handled elsewhere
            }
        }
        $methodLc = BoundMethodCallableHelper::resolveMethodLcFromCalleeSlot($block, $slot);
        if (null === $methodLc || '' === $methodLc) {
            return null;
        }
        $receiverOp = BoundMethodCallableHelper::resolveBoundMethodReceiverOperand($block, $slot);
        if (null === $receiverOp) {
            return null;
        }
        $receiver = $context->getVariableFromOp($receiverOp);
        $declaredLc = self::receiverDeclaredClassLc($receiver, $receiverOp, $block, $slot);
        $candidates = self::buildInstanceMethodCandidates($context, $declaredLc, $methodLc);
        if ([] === $candidates) {
            return null;
        }
        if (1 === \count($candidates)) {
            $proxy = $candidates[array_key_first($candidates)];
            if ($proxy instanceof ExternalMethod || !($proxy instanceof Call)) {
                return null;
            }

            return [$receiver, $proxy];
        }

        return [
            $receiver,
            new RuntimeIndirectInstanceMethodCall($receiver, $methodLc, $candidates),
        ];
    }

    /**
     * @return array{0:Native,1:string}|null proxy + cache key
     */
    public static function resolveStaticMethodNative(Context $context, string $className, string $methodName): ?array
    {
        $classLc = strtolower(ltrim($className, '\\'));
        $methodLc = strtolower($methodName);
        $proxyName = self::resolveInstanceOrStaticProxyName($context, $classLc, $methodLc);
        if (null === $proxyName || !$context->functionIsRegistered($proxyName)) {
            return null;
        }
        $proxy = $context->resolveFunctionProxy($proxyName);
        if ($proxy instanceof ExternalMethod || !($proxy instanceof Native)) {
            return null;
        }

        return [$proxy, $proxyName];
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private static function resolveStaticNamesFromBlock(Context $context): ?array
    {
        $block = $context->jitCurrentBlock ?? $context->jitEnclosingBlock;
        $callbackOp = $context->scope->argOperands[0] ?? null;
        if (!$block instanceof Block || !($callbackOp instanceof Operand)) {
            return null;
        }
        $slot = $block->slotForOperand($callbackOp);
        if (null === $slot) {
            return null;
        }
        $slots = VmBoundMethodCallable::resolveStaticArrayCallableSlots($block, $slot);
        if (null === $slots) {
            return null;
        }
        $constBlock = $slots[2];
        if (!isset($constBlock->constants[$slots[0]], $constBlock->constants[$slots[1]])) {
            return null;
        }
        $className = $constBlock->constants[$slots[0]]->toString();
        $methodName = $constBlock->constants[$slots[1]]->toString();
        if ('' === $className || '' === $methodName) {
            return null;
        }

        return [$className, $methodName];
    }

    /**
     * @param Operand $receiverOp
     */
    private static function receiverDeclaredClassLc(
        Variable $receiver,
        Operand $receiverOp,
        Block $block,
        int $calleeSlot
    ): string {
        $hint = strtolower(ltrim(
            (string) ($receiver->classUserType
                ?? $receiverOp->type?->userType
                ?? ''),
            '\\'
        ));
        if ('' !== $hint && 'object' !== $hint) {
            return $hint;
        }
        $fromSlots = BoundMethodCallableHelper::resolveBoundMethodReceiverClassName($block, $calleeSlot);
        if (null !== $fromSlots && '' !== $fromSlots) {
            return strtolower(ltrim($fromSlots, '\\'));
        }

        return $hint;
    }

    /**
     * @return array<int, Call> class id => proxy
     */
    private static function buildInstanceMethodCandidates(
        Context $context,
        string $declaredLc,
        string $methodLc
    ): array {
        $methodLc = strtolower($methodLc);
        $all = [];
        foreach ($context->type->object->allClassNamesById() as $classId => $className) {
            $classLc = strtolower(ltrim($className, '\\'));
            if ($context->type->object->hasMethod($classId, $methodLc)) {
                $vis = $context->type->object->methodVisibility($classId, $methodLc);
                if (0 !== ($vis & \PHPCfg\Func::FLAG_STATIC)) {
                    continue;
                }
            }
            $proxyName = self::resolveInstanceOrStaticProxyName($context, $classLc, $methodLc);
            if (null === $proxyName || !$context->functionIsRegistered($proxyName)) {
                continue;
            }
            if ($context->type->object->hasDeclaredClass($classLc)) {
                $vis = $context->type->object->methodVisibility(
                    $context->type->object->lookup($classLc),
                    $methodLc
                );
                if (0 !== ($vis & \PHPCfg\Func::FLAG_STATIC)) {
                    continue;
                }
            }
            $proxy = $context->resolveFunctionProxy($proxyName);
            if ($proxy instanceof ExternalMethod) {
                continue;
            }
            $all[$classId] = $proxy;
        }
        if ('' === $declaredLc || 'object' === $declaredLc) {
            return $all;
        }
        $allowed = array_flip($context->type->object->classIdsInstanceOf($declaredLc));
        if ([] === $allowed) {
            return [];
        }
        $filtered = [];
        foreach ($all as $classId => $call) {
            if (isset($allowed[$classId])) {
                $filtered[$classId] = $call;
            }
        }

        return $filtered;
    }

    private static function resolveInstanceOrStaticProxyName(
        Context $context,
        string $classLc,
        string $methodLc
    ): ?string {
        $methodLc = strtolower($methodLc);
        $visited = [];
        $current = strtolower(ltrim($classLc, '\\'));
        $start = $current;
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $proxy = $current.'::'.$methodLc;
            if ($context->functionIsRegistered($proxy)) {
                return $proxy;
            }
            if ($context->type->object->hasDeclaredClass($current)) {
                $classId = $context->type->object->lookup($current);
                $traitLc = $context->type->object->traitMethodSource($classId, $methodLc);
                if (null !== $traitLc) {
                    $traitProxy = $traitLc.'::'.$methodLc;
                    if ($context->functionIsRegistered($traitProxy)) {
                        return $traitProxy;
                    }
                }
            }
            $parent = $context->type->object->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        $fallback = $start.'::'.$methodLc;

        return $context->functionIsRegistered($fallback) ? $fallback : null;
    }
}
