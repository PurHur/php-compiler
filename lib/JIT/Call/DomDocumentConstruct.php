<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::__construct — seed thin-AOT DOMNode::$nodeType (#33607) — thin proxy via DomExtensionHooks (#36204). */
final class DomDocumentConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'document.construct',
            ...$args
        );
    }
}
