<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\JIT\Call\RuntimeIndirectClosureCall;
use PHPCompiler\JIT\Call\RuntimeVariableFunction;
use PHPCompiler\JIT\CallUnpackHelper;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\BoundMethodCallableHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LateStaticBindingHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\VariableFunctionCallHelper;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\VM\VmBoundMethodCallable;
use PHPCfg\Operand;
use PHPTypes\Type;
use PHPLLVM\Value;

/** LLVM lowering for call_user_func() / call_user_func_array() (issue #3132). */
final class JitCallUserFunc
{
    /**
     * @param list<JITVariable> $extraArgs
     */
    public static function invoke(Context $context, JITVariable $callback, array $extraArgs): Value
    {
        $direct = ClosureHelper::resolveCall($context, $callback);
        if (null !== $direct) {
            return self::boxCallResult($context, $direct, $direct, ...$extraArgs);
        }

        $literal = JitStringArg::compileTimeLiteral($callback);
        if (null !== $literal && '' !== $literal && !str_contains($literal, '::')) {
            return self::invokeCompileTimeFunction($context, $literal, $extraArgs);
        }
        // Fold compile-time 'Class::method' like array callables (#35100 / peer #35090).
        if (null !== $literal && str_contains($literal, '::')) {
            [$className, $methodName] = explode('::', $literal, 2);
            if ('' !== $className && '' !== $methodName) {
                $folded = self::tryInvokeCompileTimeClassMethodString(
                    $context,
                    $className,
                    $methodName,
                    $extraArgs
                );
                if (null !== $folded) {
                    return $folded;
                }
            }
        }

        // Fold compile-time ['Class','method'] before TYPE_VALUE → RuntimeVariableFunction
        // (that path aborts when the boxed value is an array, #35090 / peer #32299).
        $staticArray = self::tryInvokeStaticArrayCallable($context, $extraArgs);
        if (null !== $staticArray) {
            return $staticArray;
        }

        // Fold [$obj,'method'] before TYPE_VALUE → RuntimeVariableFunction (#35094 / peer #4040).
        $boundMethod = self::tryInvokeBoundMethodArrayCallable($context, $extraArgs);
        if (null !== $boundMethod) {
            return $boundMethod;
        }

        if (
            JITVariable::TYPE_STRING === $callback->type
            || JITVariable::TYPE_VALUE === $callback->type
        ) {
            $hints = self::hintedFunctionNames($context);

            return self::boxCallResult(
                $context,
                new RuntimeVariableFunction($callback, $hints),
                null,
                ...$extraArgs
            );
        }

        $closureCandidates = ClosureHelper::closureCandidates($context);
        if (
            [] !== $closureCandidates
            && (JITVariable::TYPE_OBJECT === $callback->type || JITVariable::TYPE_VALUE === $callback->type)
        ) {
            $closureClassId = $context->type->object->lookup('Closure');
            $indirect = new RuntimeIndirectClosureCall($callback, $closureCandidates, $closureClassId);

            return self::boxCallResult($context, $indirect, $indirect, ...$extraArgs);
        }

        throw new \LogicException(
            'call_user_func() callback must be a compile-time function name or closure in this compiler build; '
            .'array callables and invokable objects are VM-only (#3132)'
        );
    }

    public static function invokeArray(
        Context $context,
        JITVariable $callback,
        JITVariable $params,
        ?Block $block = null,
        ?Operand $paramsOperand = null
    ): Value {
        if (null !== $block && null !== $paramsOperand) {
            $extraArgs = self::compileTimeArrayArgs($context, $block, $paramsOperand);
            if (null !== $extraArgs) {
                return self::invoke($context, $callback, $extraArgs);
            }
        }

        if (
            JITVariable::TYPE_HASHTABLE !== $params->type
            && !($params->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            throw new \LogicException(
                'call_user_func_array() argument #2 ($args) must be an array in this compiler build'
            );
        }

        throw new \LogicException(
            'call_user_func_array() is VM-only in this compiler build; use call_user_func() for JIT/AOT (#3132)'
        );
    }

    /**
     * @return list<JITVariable>|null
     */
    private static function compileTimeArrayArgs(Context $context, Block $block, Operand $operand): ?array
    {
        $vmArray = CallUnpackHelper::tryCompileTimeArrayFromOperand($block, $operand);
        if (null === $vmArray) {
            return null;
        }
        $extraArgs = [];
        foreach ($vmArray->toArray()->iterate(true) as $value) {
            $extraArgs[] = self::jitArgFromVmConstant($context, $value);
        }

        return $extraArgs;
    }

    private static function jitArgFromVmConstant(Context $context, VmVariable $vm): JITVariable
    {
        switch ($vm->type) {
            case VmVariable::TYPE_INTEGER:
                return JITVariable::fromConstantInt($context, $vm->toInt());
            case VmVariable::TYPE_STRING:
                $lit = new Operand\Literal($vm->toString());
                $lit->type = Type::string();

                return JITVariable::fromLiteral($context, $lit);
            case VmVariable::TYPE_FLOAT:
                $lit = new Operand\Literal($vm->toFloat());
                $lit->type = Type::float();

                return JITVariable::fromLiteral($context, $lit);
            case VmVariable::TYPE_BOOLEAN:
                $lit = new Operand\Literal($vm->toBool());
                $lit->type = Type::bool();

                return JITVariable::fromLiteral($context, $lit);
            case VmVariable::TYPE_NULL:
                $nullVar = new JITVariable(
                    $context,
                    JITVariable::TYPE_NULL,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('__value__*')->constNull()
                );
                $nullVar->isNullConstant = true;

                return $nullVar;
            default:
                throw new \LogicException(
                    'call_user_func_array() compile-time args must be scalar constants in this compiler build'
                );
        }
    }

    /**
     * @param list<JITVariable> $extraArgs
     */
    private static function invokeCompileTimeFunction(
        Context $context,
        string $name,
        array $extraArgs
    ): Value {
        $lc = strtolower($name);
        if (!$context->functionIsRegistered($lc)) {
            throw new \LogicException(
                "call_user_func() callback '{$name}' is not a defined function in this compile unit"
            );
        }
        $proxy = $context->resolveFunctionProxy($lc);
        if ($proxy instanceof ExternalMethod) {
            throw new \LogicException(
                "call_user_func() callback '{$name}' is not a defined function in this compile unit"
            );
        }

        return self::boxCallResult($context, $proxy, $proxy, ...$extraArgs);
    }

    /**
     * Fold compile-time `call_user_func('Class::method', …)` (#35100 / peer #35090 array form).
     *
     * @param list<JITVariable> $extraArgs
     */
    private static function tryInvokeCompileTimeClassMethodString(
        Context $context,
        string $className,
        string $methodName,
        array $extraArgs
    ): ?Value {
        $classLc = strtolower(ltrim($className, '\\'));
        $methodLc = strtolower($methodName);
        $proxyName = self::resolveStaticProxyForClass($context, $classLc, $methodLc);
        if (null === $proxyName || !$context->functionIsRegistered($proxyName)) {
            return null;
        }
        $proxy = $context->resolveFunctionProxy($proxyName);
        if ($proxy instanceof ExternalMethod) {
            return null;
        }
        if (LateStaticBindingHelper::useRuntimeLateStatic($context)) {
            LateStaticBindingHelper::emitStoreClassId(
                $context,
                $context->constantFromInteger($context->type->object->lookup($className), 'int64')
            );
        }

        return self::boxRawCallResult($context, $proxy->call($context, ...$extraArgs));
    }

    /**
     * Fold compile-time `call_user_func(['Class','method'], …)` to a static method proxy (#35090).
     *
     * Peer: {@see \PHPCompiler\JIT::tryInitStaticArrayCallableDirect} / #32299 for `$c()` form.
     * php-src: ext/standard/basic_functions.c PHP_FUNCTION(call_user_func).
     *
     * @param list<JITVariable> $extraArgs
     */
    private static function tryInvokeStaticArrayCallable(Context $context, array $extraArgs): ?Value
    {
        $block = $context->jitCurrentBlock ?? $context->jitEnclosingBlock;
        $callbackOp = $context->jitCallUserFuncCallbackOperand
            ?? ($context->scope->argOperands[0] ?? null);
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
        if (!isset($block->constants[$slots[0]], $block->constants[$slots[1]])) {
            return null;
        }
        $className = $block->constants[$slots[0]]->toString();
        $methodName = $block->constants[$slots[1]]->toString();
        if ('' === $className || '' === $methodName) {
            return null;
        }
        $folded = self::tryInvokeCompileTimeClassMethodString(
            $context,
            $className,
            $methodName,
            $extraArgs
        );
        if (null !== $folded) {
            return $folded;
        }
        throw new \LogicException(
            "call_user_func() callback [{$className}, {$methodName}] is not a defined static method "
            .'in this compile unit (#35090)'
        );
    }

    /**
     * Fold `call_user_func([$obj,'method'], …)` to an instance method proxy (#35094).
     *
     * Peer: {@see \PHPCompiler\JIT::tryInitBoundMethodFccDirect} / #4040 for `$c()` form.
     * php-src: ext/standard/basic_functions.c PHP_FUNCTION(call_user_func).
     *
     * @param list<JITVariable> $extraArgs
     */
    private static function tryInvokeBoundMethodArrayCallable(Context $context, array $extraArgs): ?Value
    {
        $block = $context->jitCurrentBlock ?? $context->jitEnclosingBlock;
        $callbackOp = $context->jitCallUserFuncCallbackOperand
            ?? ($context->scope->argOperands[0] ?? null);
        if (!$block instanceof Block || !($callbackOp instanceof Operand)) {
            return null;
        }
        $callbackVar = $context->getVariableFromOp($callbackOp);
        if (!BoundMethodCallableHelper::isBoundMethodArrayCallee($callbackOp, $callbackVar)) {
            return null;
        }
        $slot = $block->slotForOperand($callbackOp);
        if (null === $slot) {
            return null;
        }
        $methodLc = BoundMethodCallableHelper::resolveMethodLcFromCalleeSlot($block, $slot);
        if (null === $methodLc || '' === $methodLc) {
            return null;
        }
        $receiverOp = BoundMethodCallableHelper::resolveBoundMethodReceiverOperand($block, $slot);
        if (null === $receiverOp) {
            return null;
        }
        if (null === $receiverOp->type || Type::TYPE_OBJECT !== $receiverOp->type->type) {
            return null;
        }
        $receiverVar = $context->getVariableFromOp($receiverOp);
        $classHint = BoundMethodCallableHelper::resolveBoundMethodReceiverClassName($block, $slot);
        if (null === $classHint || '' === $classHint) {
            $classHint = (string) ($receiverVar->classUserType ?? $receiverOp->type->userType ?? '');
        }
        $classLc = strtolower(ltrim($classHint, '\\'));
        if ('' === $classLc || 'object' === $classLc) {
            return null;
        }
        $proxyName = self::resolveStaticProxyForClass($context, $classLc, $methodLc);
        if (null === $proxyName || !$context->functionIsRegistered($proxyName)) {
            throw new \LogicException(
                "call_user_func() callback [object, {$methodLc}] is not a defined instance method "
                ."on {$classHint} in this compile unit (#35094)"
            );
        }
        $proxy = $context->resolveFunctionProxy($proxyName);
        if ($proxy instanceof ExternalMethod) {
            throw new \LogicException(
                "call_user_func() callback [object, {$methodLc}] is not a defined instance method "
                ."on {$classHint} in this compile unit (#35094)"
            );
        }
        // Instance proxies take $this as arg0 (peer initJitMethodCall scope->args).
        $callArgs = array_merge([$receiverVar], $extraArgs);

        return self::boxRawCallResult($context, $proxy->call($context, ...$callArgs));
    }

    /**
     * Box a Call::call() result for call_user_func* return (#35100).
     *
     * User Func proxies return `__value__` by value; treating that like `__value__*` and
     * structGep-ing crashes the compiler. {@see JitValueBox::coerceToValuePtrForStore}.
     */
    private static function boxRawCallResult(Context $context, Value $raw): Value
    {
        return JitValueBox::coerceToValuePtrForStore($context, $raw);
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

    /**
     * @param list<JITVariable> $extraArgs
     */
    private static function boxCallResult(
        Context $context,
        Call $proxy,
        Call|string|null $label,
        JITVariable ...$extraArgs
    ): Value {
        return self::boxRawCallResult($context, $proxy->call($context, ...$extraArgs));
    }

    /**
     * @return list<string>
     */
    private static function hintedFunctionNames(Context $context): array
    {
        $block = $context->jitCurrentBlock;
        if (null === $block) {
            return [];
        }

        return array_values(array_unique(array_merge(
            VariableFunctionCallHelper::funDefNamesInCompilationUnit($block),
            VariableFunctionCallHelper::coalesceBranchLiteralHints($block)
        )));
    }
}
