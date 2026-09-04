<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * SimpleXMLElement::__construct() — user-script AOT (#19306).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\simplexml} (#36204). php-src: ext/simplexml/sxe.c
 */
final class SimpleXMLElementConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireSimpleXml()->construct($context, ...$args);
    }
}
