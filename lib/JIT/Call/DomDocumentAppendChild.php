<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAppendChild;
use PHPCompiler\ext\dom\JitDomAppendChildUserScript;
use PHPCompiler\ext\dom\JitDomRequireDomNodeArg;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::appendChild() — parentNode + documentElement + reparent (#18927, #21687, #27410). */
final class DomDocumentAppendChild implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_doc_ac_invoke_cont');
        if (\count($args) >= 2 && JitDomRequireDomNodeArg::guardOrAbort($context, $args[1], 'DOMNode::appendChild', 1, 'node')) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }
        JitDomAppendChild::invoke($context, $args[0], $args[1]);

        return JitDomAppendChildUserScript::invokeDocumentAppend(
            $context,
            $args[0],
            $args[1]
        );
    }
}
