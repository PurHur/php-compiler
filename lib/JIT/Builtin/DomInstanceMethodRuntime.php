<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomInstanceMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\JIT\Variable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value;

/**
 * JIT/AOT bridge for ext/dom instance methods via VmDomInstanceInvoke (#17130, #17391).
 *
 * php-src: ext/dom/php_dom.c — DOM*::method handlers
 */
final class DomInstanceMethodRuntime
{
    public const MAX_EXTRA_ARGS = 4;

    private const HELPER_PATH = '/ext/dom/VmDomInstanceInvoke.php';

    private const ABI_PREFIX = '__phpc_jit_dom_instance_method_';

    /** @var array<int, string> */
    private const INVOKE_BY_ARITY = [
        0 => 'PHPCompiler\\ext\\dom\\VmDomInstanceInvoke::invoke0Object',
        1 => 'PHPCompiler\\ext\\dom\\VmDomInstanceInvoke::invoke1Object',
        2 => 'PHPCompiler\\ext\\dom\\VmDomInstanceInvoke::invoke2Object',
        3 => 'PHPCompiler\\ext\\dom\\VmDomInstanceInvoke::invoke3Object',
        4 => 'PHPCompiler\\ext\\dom\\VmDomInstanceInvoke::invoke4Object',
    ];

    /** @var list<string> */
    public const COMPILED_HELPER_LOGICALS = [
        self::INVOKE_BY_ARITY[0],
        self::INVOKE_BY_ARITY[1],
        self::INVOKE_BY_ARITY[2],
        self::INVOKE_BY_ARITY[3],
        self::INVOKE_BY_ARITY[4],
    ];

    public static function invoke(
        Context $context,
        int $extraArgCount,
        string $methodLc,
        Variable $receiver,
        Variable ...$extraArgs
    ): Value {
        if ($extraArgCount !== \count($extraArgs)) {
            throw new \LogicException('DomInstanceMethodRuntime arity mismatch');
        }
        self::assertValidArity($extraArgCount);
        self::ensureBridge($context, $extraArgCount);
        $llvmArgs = [
            self::receiverValuePtr($context, $receiver),
            $context->builder->load($context->constantStringFromString($methodLc)),
        ];
        foreach ($extraArgs as $arg) {
            $llvmArgs[] = JitValueBox::valuePtrFromVariable($context, $arg);
        }

        return $context->builder->call(
            $context->lookupFunction(self::abiForArity($extraArgCount)),
            ...$llvmArgs
        );
    }

    public static function ensureLinked(Context $context): void
    {
        VmActiveContextLlvm::ensureAbi($context);
        for ($i = 0; $i <= self::MAX_EXTRA_ARGS; ++$i) {
            self::ensureBridge($context, $i);
        }
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function ensureBridge(Context $context, int $extraArgCount): void
    {
        self::assertValidArity($extraArgCount);
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        if (JitDomInstanceMethodKernel::shouldUse($context)) {
            JitDomInstanceMethodKernel::ensureBridge($context, $extraArgCount);

            return;
        }

        $abi = self::abiForArity($extraArgCount);
        $probe = $context->module->getNamedFunction($abi);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abi, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        NestedVmVariableMethodLlvm::ensureMethod($context, 'resolveindirect');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'toobject');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tostring');
        foreach (['string', 'int', 'null', 'object', 'bool'] as $writeMethod) {
            NestedVmVariableMethodLlvm::ensureMethod($context, $writeMethod);
        }
        self::ensureActiveContextProxy($context);

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $paramTypes = [$valuePtr, $strPtr];
        for ($i = 0; $i < $extraArgCount; ++$i) {
            $paramTypes[] = $valuePtr;
        }
        JitVmHelperLink::ensureBridge(
            $context,
            $abi,
            'dom_instance_method_bridge_'.$extraArgCount,
            $paramTypes,
            $valuePtr,
            self::invokeLogicalForArity($extraArgCount),
            self::HELPER_PATH,
            self::COMPILED_HELPER_LOGICALS,
            '#17130'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function assertValidArity(int $extraArgCount): void
    {
        if ($extraArgCount < 0 || $extraArgCount > self::MAX_EXTRA_ARGS) {
            throw new \LogicException('Invalid DOM instance method JIT bridge arity');
        }
    }

    public static function abiForArity(int $extraArgCount): string
    {
        return self::ABI_PREFIX.$extraArgCount;
    }

    public static function invokeLogicalForArity(int $extraArgCount): string
    {
        self::assertValidArity($extraArgCount);

        return self::INVOKE_BY_ARITY[$extraArgCount];
    }

    private static function receiverValuePtr(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_VALUE === $receiver->type) {
            return JitValueBox::valuePtrFromVariable($context, $receiver);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (Variable::TYPE_OBJECT === $receiver->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $ptr,
                $context->helper->loadValue($receiver)
            );

            return $ptr;
        }

        throw new \LogicException('DOM instance method receiver must be object or value box');
    }

    public static function ensureActiveContextProxy(Context $context): void
    {
        $proxy = 'phpcompiler\\vm\\vmactivecontextjithelper::resolve';
        if ($context->functionIsRegistered($proxy)) {
            return;
        }
        VmActiveContextLlvm::ensureAbi($context);
        $context->functionProxies[$proxy] = new \PHPCompiler\JIT\Call\VmActiveContextResolve();
    }
}
