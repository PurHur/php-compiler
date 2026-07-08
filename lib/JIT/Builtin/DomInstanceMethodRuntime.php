<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\JIT\Variable;
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
    private const COMPILED_HELPERS = [
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
        if ($extraArgCount > self::MAX_EXTRA_ARGS) {
            throw new \LogicException('Too many arguments for DOM instance method JIT bridge');
        }
        self::ensureBridge($context, $extraArgCount);
        $llvmArgs = [
            self::receiverValuePtr($context, $receiver),
            $context->builder->load($context->constantStringFromString($methodLc)),
        ];
        foreach ($extraArgs as $arg) {
            $llvmArgs[] = JitValueBox::valuePtrFromVariable($context, $arg);
        }

        return $context->builder->call(
            $context->lookupFunction(self::ABI_PREFIX.$extraArgCount),
            ...$llvmArgs
        );
    }

    public static function ensureLinked(Context $context): void
    {
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
        if ($extraArgCount < 0 || $extraArgCount > self::MAX_EXTRA_ARGS) {
            throw new \LogicException('Invalid DOM instance method JIT bridge arity');
        }
        $abi = self::ABI_PREFIX.$extraArgCount;
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
            self::INVOKE_BY_ARITY[$extraArgCount],
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#17130'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
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
}
