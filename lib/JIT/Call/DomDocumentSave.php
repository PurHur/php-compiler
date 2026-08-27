<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomSave;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::save() — user-script AOT (#35546 leftover of #18435). */
final class DomDocumentSave implements Call
{
    /** @var list<string> */
    public array $paramNames = ['filename', 'options='];

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomSave::invoke($context, ...$args);
    }
}
