<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * SimpleXMLElement::count() / Iterator leftover of hasChildren (#26863, #35827, #35844).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\simplexml} (#36204). php-src: ext/simplexml/sxe.c
 */
final class SimpleXMLElementCount implements Call
{
    public function __construct(private string $name = 'count')
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $hooks = $context->extensionLowering->requireSimpleXml();
        if ('count' !== $this->name) {
            return $hooks->countNamed($context, $this->name, ...$args);
        }

        return $hooks->count($context, ...$args);
    }
}
