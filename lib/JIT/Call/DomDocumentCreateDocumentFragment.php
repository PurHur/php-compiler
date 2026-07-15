<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNodeLiveMutationRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::createDocumentFragment() — user-script AOT (#18951). */
final class DomDocumentCreateDocumentFragment implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_cdf_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMDocument::createDocumentFragment() called without $this');
        }

        return DomNodeLiveMutationRuntime::invokeCreateDocumentFragment($context, $args[0]);
    }
}
