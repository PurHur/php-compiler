<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomAdoptNodeRuntime;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::adoptNode() (#29853).
 *
 * Peer: {@see JitDomImportNode} (#19212). Thin-standalone AOT uses the document-method
 * kernel bridge for DomRegistry reparent, but returns the caller-side child
 * {@see __object__*} (not the NestedJIT return) — round-tripping the same node
 * through NestedJIT object returns leaves a pointer that segfaults on property
 * fetch / appendChild (createElement helper returns a *new* object and is fine).
 *
 * Profile gate is evaluated in this user-script lowerer (not inside the helper TU):
 * helper-runtime objects are profile-agnostic and would otherwise bake 8.4 support
 * into default-profile binaries.
 */
final class JitDomAdoptNode
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::adoptNode() expects receiver and node');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_adopt_node_cont');

        if (!CompilerVersion::supportsDomDocumentAdoptNode()) {
            return self::emitNotYetImplemented($context);
        }

        DomAdoptNodeRuntime::ensureLinked($context);
        $document = self::loadObjectArg($context, $args[0]);
        $node = self::loadObjectArg($context, $args[1]);
        // DomRegistry reparent (documentId / detach). Discard NestedJIT object return —
        // reuse the caller-side node pointer for the call ABI (#29853).
        $context->builder->call(
            $context->lookupFunction(DomAdoptNodeRuntime::ABI_NAME),
            $document,
            $node
        );

        return self::boxObjectResult($context, $node);
    }

    private static function emitNotYetImplemented(Context $context): Value
    {
        $message = 'Not yet implemented';
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'Error', $message);
        } else {
            ErrorRaise::emitRaise($context, $message);
            $abort = $context->module->getNamedFunction('phpc_jit_abort_if_pending_error');
            if (null !== $abort) {
                $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_error'));
            } else {
                $context->builder->call($context->lookupFunction('abort'));
            }
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }

        // Unreachable after throw — satisfy call ABI with a null object box.
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMDocument::adoptNode() expects object nodes');
    }

    private static function boxObjectResult(Context $context, Value $object): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $object
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
