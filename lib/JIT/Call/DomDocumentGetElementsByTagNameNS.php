<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::getElementsByTagNameNS() — user-script AOT (#32415, php-src php_dom.c). */
final class DomDocumentGetElementsByTagNameNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'document.getElementsByTagNameNS',
            ...$args
        );
    }
}
