<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::saveHTMLFile() — user-script AOT (#35549 leftover of #18268 / #35546). */
final class DomDocumentSaveHTMLFile implements Call
{
    /** @var list<string> */
    public array $paramNames = ['filename'];

    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'document.saveHTMLFile',
            ...$args
        );
    }
}
