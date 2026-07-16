<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomCreateComment;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::createComment() — user-script AOT (#19455). */
final class DomDocumentCreateComment implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomCreateComment::invoke($context, ...$args);
    }
}
