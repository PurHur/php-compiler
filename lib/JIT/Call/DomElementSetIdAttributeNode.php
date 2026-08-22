<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomRequireDomNodeArg;
use PHPCompiler\ext\dom\JitDomSetIdAttribute;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::setIdAttributeNode() — user-script AOT (#29284, #33758). */
final class DomElementSetIdAttributeNode implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setidattributenode_invoke_cont');

        // Skip id-map cache sync after null TypeError (#33758 / peer #33753).
        if (\count($args) >= 2
            && JitDomRequireDomNodeArg::guardOrAbort(
                $context,
                $args[1],
                'DOMElement::setIdAttributeNode',
                1,
                'attr',
                'DOMAttr'
            )
        ) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }

        return JitDomSetIdAttribute::invokeNode($context, ...$args);
    }
}
