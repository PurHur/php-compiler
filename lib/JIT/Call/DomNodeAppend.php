<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\DomUserScriptLiveTagListLlvm;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomDocumentMethodUserScriptLlvm;
use PHPCompiler\JIT\Builtin\DomNodeLiveMutationRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::append() — user-script AOT (#18951, #19208). */
final class DomNodeAppend implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_append_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::append() called without $this');
        }

        $result = DomNodeLiveMutationRuntime::invokeAppend(
            $context,
            \count($args) - 1,
            $args[0],
            ...\array_slice($args, 1)
        );
        if (\count($args) >= 2 && DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            DomUserScriptLiveTagListLlvm::incrementForChildArg($context, $args[1]);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_append_live_inc_cont');
        }

        return $result;
    }
}
