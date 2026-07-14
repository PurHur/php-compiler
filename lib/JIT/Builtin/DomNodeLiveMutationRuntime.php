<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\Variable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value;

/**
 * JIT/AOT link for DOMNode::{append,prepend,replaceChildren} via PHP helpers (#18951).
 */
final class DomNodeLiveMutationRuntime
{
    public const MAX_EXTRA_ARGS = 4;

    private const HELPER_PATH = '/ext/dom/DomCreateElementJitHelper.php';

    public const ABI_CREATE_FRAGMENT = '__phpc_dom_create_document_fragment';

    private const HELPER_CREATE_FRAGMENT = 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::createDocumentFragmentArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_CREATE_FRAGMENT,
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendArgv1',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendArgv2',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendArgv3',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependArgv1',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependArgv2',
    ];

    public static function invokeAppend(Context $context, int $extraArgCount, Variable $receiver, Variable ...$extraArgs): Value
    {
        return self::invokeMutation($context, 'append', $extraArgCount, $receiver, ...$extraArgs);
    }

    public static function invokePrepend(Context $context, int $extraArgCount, Variable $receiver, Variable ...$extraArgs): Value
    {
        return self::invokeMutation($context, 'prepend', $extraArgCount, $receiver, ...$extraArgs);
    }

    public static function invokeReplaceChildren(Context $context, int $extraArgCount, Variable $receiver, Variable ...$extraArgs): Value
    {
        return self::invokeMutation($context, 'replacechildren', $extraArgCount, $receiver, ...$extraArgs);
    }

    public static function invokeCreateDocumentFragment(Context $context, Variable $receiver): Value
    {
        self::ensureCreateFragmentBridge($context);
        $parentObj = self::receiverObject($context, $receiver);
        $result = $context->builder->call(
            $context->lookupFunction(self::ABI_CREATE_FRAGMENT),
            $parentObj
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $result
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    public static function appendAbi(int $extraArgCount): string
    {
        return '__phpc_dom_node_append_'.$extraArgCount;
    }

    public static function prependAbi(int $extraArgCount): string
    {
        return '__phpc_dom_node_prepend_'.$extraArgCount;
    }

    public static function replaceChildrenAbi(int $extraArgCount): string
    {
        return '__phpc_dom_node_replace_children_'.$extraArgCount;
    }

    private static function invokeMutation(
        Context $context,
        string $kind,
        int $extraArgCount,
        Variable $receiver,
        Variable ...$extraArgs
    ): Value {
        if ($extraArgCount !== \count($extraArgs)) {
            throw new \LogicException('DomNodeLiveMutationRuntime arity mismatch');
        }
        if ($extraArgCount < 1 || $extraArgCount > self::MAX_EXTRA_ARGS) {
            throw new \LogicException('DomNodeLiveMutationRuntime unsupported arity');
        }
        self::ensureMutationBridge($context, $kind, $extraArgCount);
        $abi = self::abiFor($kind, $extraArgCount);
        $llvmArgs = [self::receiverObject($context, $receiver)];
        foreach ($extraArgs as $arg) {
            $llvmArgs[] = JitValueBox::valuePtrFromVariable($context, $arg);
        }
        $context->builder->call($context->lookupFunction($abi), ...$llvmArgs);

        return self::nullValuePtr($context);
    }

    private static function ensureMutationBridge(Context $context, string $kind, int $extraArgCount): void
    {
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            match ($kind) {
                'append' => DomDocumentMethodUserScriptLlvm::ensureAppendBridge($context, $extraArgCount),
                'prepend' => DomDocumentMethodUserScriptLlvm::ensurePrependBridge($context, $extraArgCount),
                'replacechildren' => DomDocumentMethodUserScriptLlvm::ensureReplaceChildrenBridge($context, $extraArgCount),
                default => throw new \LogicException('Unknown DOM live-mutation kind'),
            };

            return;
        }

        $abi = self::abiFor($kind, $extraArgCount);
        $entryBlock = 'dom_'.$kind.'_bridge_'.$extraArgCount;
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);
        JitVmHelperLink::ensureBridge(
            $context,
            $abi,
            $entryBlock,
            self::bridgeParamTypes($context, $extraArgCount),
            $context->context->voidType(),
            self::helperLogicalFor($kind, $extraArgCount),
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18951'
        );
    }

    private static function ensureCreateFragmentBridge(Context $context): void
    {
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            DomDocumentMethodUserScriptLlvm::ensureCreateDocumentFragmentBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CREATE_FRAGMENT,
            'dom_create_document_fragment_bridge',
            [$objPtr],
            $objPtr,
            self::HELPER_CREATE_FRAGMENT,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18951'
        );
    }

    /** @return list<\PHPLLVM\Type> */
    private static function bridgeParamTypes(Context $context, int $extraArgCount): array
    {
        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $paramTypes = [$objPtr];
        for ($i = 0; $i < $extraArgCount; ++$i) {
            $paramTypes[] = $valuePtr;
        }

        return $paramTypes;
    }

    private static function receiverObject(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('DOM live-mutation receiver must be object or value box');
    }

    private static function nullValuePtr(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function abiFor(string $kind, int $extraArgCount): string
    {
        return match ($kind) {
            'append' => self::appendAbi($extraArgCount),
            'prepend' => self::prependAbi($extraArgCount),
            'replacechildren' => self::replaceChildrenAbi($extraArgCount),
            default => throw new \LogicException('Unknown DOM live-mutation kind'),
        };
    }

    private static function helperLogicalFor(string $kind, int $extraArgCount): string
    {
        $suffix = 'Argv'.$extraArgCount;

        return match ($kind) {
            'append' => 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::append'.$suffix,
            'prepend' => 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prepend'.$suffix,
            'replacechildren' => 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::replaceChildren'.$suffix,
            default => throw new \LogicException('Unknown DOM live-mutation kind'),
        };
    }
}
