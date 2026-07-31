<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomLivingApiRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::compareDocumentPosition() — user-script AOT (#25878). */
final class DomNodeCompareDocumentPosition implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_compare_document_position_invoke_cont');
        if (\count($args) < 2) {
            throw new \LogicException('DOMNode::compareDocumentPosition() expects receiver and other');
        }

        return DomLivingApiRuntime::invokeCompareDocumentPosition($context, $args[0], $args[1]);
    }
}
