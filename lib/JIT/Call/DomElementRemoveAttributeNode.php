<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAttributeNodeNS;
use PHPCompiler\ext\dom\JitDomRequireDomNodeArg;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::removeAttributeNode() — user-script AOT (php-src element.c; #33577). */
final class DomElementRemoveAttributeNode implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_removeattrnode_invoke_cont');

        // Early-return on null TypeError so saveXML sync is not emitted (#33753 verify).
        if (\count($args) >= 2
            && JitDomRequireDomNodeArg::guardOrAbort(
                $context,
                $args[1],
                'DOMElement::removeAttributeNode',
                1,
                'attr',
                'DOMAttr'
            )
        ) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }

        $result = JitDomAttributeNodeNS::invokeRemoveAttributeNode($context, ...$args);

        // Drop attr from saveXML open-tag suffix (peer removeAttribute #33509 / #33577).
        if (\count($args) >= 1) {
            JitDomAttributeNodeNS::syncSaveXmlAttrSuffixAfterRemoveAttributeNode($context, $args[0]);
        }

        return $result;
    }
}
