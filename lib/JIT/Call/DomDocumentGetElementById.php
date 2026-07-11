<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomGetElementById;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::getElementById() — user-script AOT (#17954). */
final class DomDocumentGetElementById implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_gei_call_cont');

        $result = JitDomGetElementById::invoke($context, ...$args);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_gei_post_invoke');

        return $result;
    }
}
