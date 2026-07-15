<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAttributeNodeNS;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::createAttributeNS() — user-script AOT (#19265). */
final class DomDocumentCreateAttributeNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_createattrns_invoke_cont');

        return JitDomAttributeNodeNS::invokeCreate($context, ...$args);
    }
}
