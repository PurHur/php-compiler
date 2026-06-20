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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\VariableFunctionCallHelper;
use PHPCompiler\VM\Variable as VmVariable;
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
     * @param list<JITVariable> $extraArgs
     */
    private static function boxCallResult(
        Context $context,
        Call $proxy,
        Call|string|null $label,
        JITVariable ...$extraArgs
    ): Value {
        $raw = $proxy->call($context, ...$extraArgs);
        $slot = JitValueBox::alloc($context);
        $rawTy = $context->getStringFromType($raw->typeOf());
        if ('int64' === $rawTy) {
            JitValueBox::writeLong($context, $slot, $raw);

            return JitValueBox::pointer($context, $slot);
        }
        if ('double' === $rawTy) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                JitValueBox::pointer($context, $slot),
                $raw
            );

            return JitValueBox::pointer($context, $slot);
        }
        if ('int1' === $rawTy || 'bool' === $rawTy) {
            JitValueBox::writeBool($context, $slot, $raw);

            return JitValueBox::pointer($context, $slot);
        }
        if ('__value__*' === $rawTy || '__value__' === $rawTy) {
            JitValueBox::copyFromPointer(
                $context,
                $slot,
                JitValueBox::normalizeValuePtr($context, $raw)
            );

            return JitValueBox::pointer($context, $slot);
        }
        if ('__string__*' === $rawTy) {
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $slot),
                $raw
            );

            return JitValueBox::pointer($context, $slot);
        }
        JitValueBox::copyFromPointer(
            $context,
            $slot,
            JitValueBox::normalizeValuePtr($context, $raw)
        );

        return JitValueBox::pointer($context, $slot);
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
