<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\VmDom;
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

    public const ABI_CREATE_FRAGMENT_OBJECT = '__phpc_dom_create_document_fragment_object';

    private const HELPER_CREATE_FRAGMENT = 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::createDocumentFragmentArgv';

    private const HELPER_CREATE_FRAGMENT_OBJECT = 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::createDocumentFragmentObjectArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_CREATE_FRAGMENT,
        self::HELPER_CREATE_FRAGMENT_OBJECT,
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendObjectArgv1',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendObjectArgv2',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendObjectArgv3',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependObjectArgv1',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependObjectArgv2',
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
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            DomDocumentMethodUserScriptLlvm::ensureCreateDocumentFragmentObjectBridge($context);
            $abi = self::ABI_CREATE_FRAGMENT_OBJECT;
        } else {
            self::ensureCreateFragmentBridge($context);
            $abi = self::ABI_CREATE_FRAGMENT;
        }
        $parentObj = self::receiverObject($context, $receiver);
        $result = $context->builder->call(
            $context->lookupFunction($abi),
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

    public static function appendObjectAbi(int $extraArgCount): string
    {
        return '__phpc_dom_node_append_object_'.$extraArgCount;
    }

    public static function prependObjectAbi(int $extraArgCount): string
    {
        return '__phpc_dom_node_prepend_object_'.$extraArgCount;
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
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            $orderedArgs = 'prepend' === $kind ? array_reverse($extraArgs) : $extraArgs;
            $firstArg = $extraArgs[0];
            $lastArg = $extraArgs[\count($extraArgs) - 1];
            $firstChildObj = null;
            $lastChildObj = null;
            foreach ($orderedArgs as $arg) {
                $appended = self::invokeUserScriptMutationArg($context, $kind, $receiver, $arg);
                if ($arg === $firstArg) {
                    $firstChildObj = $appended;
                }
                if ($arg === $lastArg) {
                    $lastChildObj = $appended;
                }
            }
            if (null !== $firstChildObj && null !== $lastChildObj) {
                self::syncChildLinkSlots($context, $receiver, $firstChildObj, $lastChildObj);
            }

            return self::nullValuePtr($context);
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

    private static function invokeUserScriptMutationArg(
        Context $context,
        string $kind,
        Variable $receiver,
        Variable $arg
    ): Value {
        if (Variable::TYPE_STRING === $arg->type) {
            self::ensureMutationBridge($context, $kind, 1);
            $abi = self::abiFor($kind, 1);
            $context->builder->call(
                $context->lookupFunction($abi),
                self::receiverObject($context, $receiver),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );

            return self::receiverObject($context, $receiver);
        }
        if (\in_array($arg->type, [Variable::TYPE_OBJECT, Variable::TYPE_VALUE], true)) {
            self::ensureObjectMutationBridge($context, $kind, 1);
            $abi = self::objectAbiFor($kind, 1);
            $context->builder->call(
                $context->lookupFunction($abi),
                self::receiverObject($context, $receiver),
                self::mutationArgObject($context, $arg)
            );

            return self::mutationArgObject($context, $arg);
        }
        self::ensureMutationBridge($context, $kind, 1);
        $abi = self::abiFor($kind, 1);
        $context->builder->call(
            $context->lookupFunction($abi),
            self::receiverObject($context, $receiver),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );

        return self::receiverObject($context, $receiver);
    }

    /** @param list<Variable> $extraArgs */
    private static function canUseObjectMutationBridge(array $extraArgs): bool
    {
        if ([] === $extraArgs) {
            return false;
        }
        foreach ($extraArgs as $arg) {
            if (!\in_array($arg->type, [Variable::TYPE_OBJECT, Variable::TYPE_VALUE], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mirror live child links into LLVM property slots for user-script AOT reads (#18951).
     */
    private static function syncChildLinkSlots(
        Context $context,
        Variable $receiver,
        Value $firstChildObj,
        Value $lastChildObj
    ): void {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, Variable::TYPE_VALUE);
            }
        }

        $receiverObj = self::receiverObject($context, $receiver);
        $firstJit = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $firstChildObj);
        $lastJit = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $lastChildObj);
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiverObj, 'DOMNode', VmDom::PROP_FIRST_CHILD),
            $firstJit,
            Variable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiverObj, 'DOMNode', VmDom::PROP_LAST_CHILD),
            $lastJit,
            Variable::TYPE_VALUE
        );
    }

    private static function ensureObjectMutationBridge(Context $context, string $kind, int $extraArgCount): void
    {
        match ($kind) {
            'append' => DomDocumentMethodUserScriptLlvm::ensureAppendObjectBridge($context, $extraArgCount),
            'prepend' => DomDocumentMethodUserScriptLlvm::ensurePrependObjectBridge($context, $extraArgCount),
            default => throw new \LogicException('DOM object live-mutation bridge unsupported for '.$kind),
        };
    }

    private static function mutationArgObject(Context $context, Variable $arg): Value
    {
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOM object live-mutation arg must be object or value box');
    }

    private static function objectAbiFor(string $kind, int $extraArgCount): string
    {
        return match ($kind) {
            'append' => self::appendObjectAbi($extraArgCount),
            'prepend' => self::prependObjectAbi($extraArgCount),
            default => throw new \LogicException('Unknown DOM object live-mutation kind'),
        };
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
