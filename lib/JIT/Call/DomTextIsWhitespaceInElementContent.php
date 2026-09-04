<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DOMText::isWhitespaceInElementContent() — user-script AOT (php-src xmlIsBlankNode) (#32396).
 *
 * Also the legacy alias isElementContentWhitespace().
 */
final class DomTextIsWhitespaceInElementContent implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'text.isWhitespaceInElementContent',
            ...$args
        );
    }
}
