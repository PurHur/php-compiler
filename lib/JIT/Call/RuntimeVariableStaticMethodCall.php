<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Runtime `Class::$methodVar()` / `static::$methodVar()` dispatch (#34937).
 *
 * Peer of {@see RuntimeVariableFunction} for free functions and
 * {@see RuntimeIndirectStaticMethodCall} for `$obj::literalMethod()`.
 * Uses case-insensitive string compare (zend method names are case-insensitive)
 * then {@see JitValueBox::coerceToValuePtrForStore} — same result boxing as
 * RuntimeIndirectStaticMethodCall (not VariableFunctionCallRuntime free-fn boxing).
 *
 * php-src: Zend/zend_execute.c — ZEND_INIT_STATIC_METHOD_CALL (method from CV/TMP)
 */
final class RuntimeVariableStaticMethodCall implements Call
{
    private static int $blockSeq = 0;

    /**
     * @param array<string, Call> $candidatesByMethodLc lowercase method => proxy
     *                                                  (may nest {@see RuntimeIndirectStaticMethodCall})
     */
    public function __construct(
        public readonly Variable $methodNameVar,
        public readonly array $candidatesByMethodLc,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $this->candidatesByMethodLc) {
            $context->builder->call($context->lookupFunction('abort'));

            return JitValueBox::alloc($context);
        }

        $argc = \count($args);
        $compatible = [];
        foreach ($this->candidatesByMethodLc as $methodLc => $proxy) {
            if (!$proxy instanceof Call) {
                continue;
            }
            if (!$this->proxyAcceptsArgc($proxy, $argc)) {
                continue;
            }
            $compatible[$methodLc] = $proxy;
        }
        if ([] === $compatible) {
            $context->builder->call($context->lookupFunction('abort'));

            return JitValueBox::alloc($context);
        }

        $nameStr = JitStringArg::lower($context, $this->methodNameVar, 'static method name');
        $tag = 'vsm'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'var_static_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'var_static_undef_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $zero = $context->getTypeFromString('__value__*')->constNull();
        $context->builder->store($zero, $resultSlot);

        $i64 = $context->getTypeFromString('int64');
        $zeroI64 = $i64->constInt(0, false);
        $names = array_keys($compatible);
        $n = \count($names);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'var_static_check_'.$tag.'_'.$i);
        }

        foreach ($names as $i => $methodLc) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $lit = $context->builder->load($context->constantStringFromString($methodLc));
            $cmp = JitStringCompare::strcasecmp($context, $nameStr, $lit);
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $zeroI64);
            $onMatch = BasicBlockHelper::append($context, 'var_static_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $undef;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $proxy = $compatible[$methodLc];
            // Emitting every name arm with this site's argc: skip arms that throw
            // (arity-strict stubs) so one bad candidate cannot abort the module (#36380).
            try {
                $raw = $proxy->call($context, ...$args);
                $context->builder->store(
                    JitValueBox::coerceToValuePtrForStore($context, $raw),
                    $resultSlot
                );
            } catch (\LogicException) {
                BasicBlockHelper::ensureOpenInsertBlock($context, 'var_static_arm_fail_'.$tag.'_'.$i);
                $context->builder->call($context->lookupFunction('abort'));
                $context->builder->store($zero, $resultSlot);
            }
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($undef);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * Drop candidates whose declared argc cannot accept this call site (#36380).
     * Instance `$this->$name($a,$b)` must not emit 1-arg method arms (and vice versa).
     */
    private function proxyAcceptsArgc(Call $proxy, int $argc): bool
    {
        if (!($proxy instanceof Native)) {
            return true;
        }
        $n = \count($proxy->argTypes);
        if (null !== $proxy->variadicArgIndex) {
            return $argc >= $proxy->variadicArgIndex;
        }
        $defaults = \count($proxy->defaultArgs);
        $min = max(0, $n - $defaults);

        return $argc >= $min && $argc <= $n;
    }
}
