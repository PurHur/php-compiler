<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAppendChildUserScript;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::appendChild() — user-script AOT documentElement store (#18927). */
final class DomDocumentAppendChild implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_doc_ac_invoke_cont');

        return JitDomAppendChildUserScript::invokeDocumentAppend(
            $context,
            $args[0],
            $args[1]
        );
    }
}
