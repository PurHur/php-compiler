<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\DomUserScriptDoctypeLlvm;
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
        // DocumentFragment: move children onto the document (php-src fragment expand).
        // LiveSlots expand targets Element parents; Document uses UserScript (#33564).
        // createDocumentType → appendChild: stamp doctype for saveXML (#33584).
        if (($args[1]->compileTimeDomTagName ?? null) === \PHPCompiler\ext\dom\JitDomCreateDocumentType::TAG_KIND) {
            DomUserScriptDoctypeLlvm::markAttached();
        }

        return JitDomAppendChildUserScript::invokeDocumentAppendMaybeFragment(
            $context,
            $args[0],
            $args[1]
        );
    }
}
