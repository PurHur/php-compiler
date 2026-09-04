<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * SimpleXMLElement::children() — user-script AOT (#27535).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\simplexml} (#36204). php-src: ext/simplexml/sxe.c
 */
final class SimpleXMLElementChildren implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireSimpleXml()->children($context, ...$args);
    }
}
