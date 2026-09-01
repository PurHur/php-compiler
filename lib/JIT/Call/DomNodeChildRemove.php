<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\DomClassMethod;
use PHPCompiler\ext\dom\DomJitArgc;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNodeChildNodeMutationRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** DOMNode::remove() — user-script AOT ChildNode (#26752). */
final class DomNodeChildRemove implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_child_remove_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::remove() called without $this');
        }
        $given = VmClassMethod::jitUserArgCount($context, $args);
        if (0 !== $given) {
            $function = DomJitArgc::childNodeRemoveAceFunction($context, $args[0]);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                DomClassMethod::exactUserArgCountMessage($function, 0, $given)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_child_remove_ace_cont');

            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return DomNodeChildNodeMutationRuntime::invokeRemove($context, $args[0]);
    }
}
