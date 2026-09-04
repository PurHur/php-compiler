<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * SimpleXMLElement::__get() / __set() — user-script AOT (#26863, #35820).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\simplexml} (#36204). php-src: ext/simplexml/sxe.c
 */
final class SimpleXMLElementGet implements Call
{
    public function __construct(private string $name = '__get')
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $hooks = $context->extensionLowering->requireSimpleXml();
        if ('__set' === $this->name) {
            return $hooks->set($context, ...$args);
        }

        return $hooks->get($context, ...$args);
    }
}
