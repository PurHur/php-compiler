<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\DomSaveXMLRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::saveXML() — user-script AOT (#18268). */
final class DomDocumentSaveXML implements Call
{
    /** @var list<string> */
    public array $paramNames = ['node=', 'options='];

    public function call(Context $context, Variable ...$args): Value
    {
        DomSaveXMLRuntime::ensureLinked($context);

        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'document.saveXML',
            ...$args
        );
    }
}
