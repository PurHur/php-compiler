<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * SimpleXMLElement ArrayAccess — user-script AOT (#26863, leftover of #35810).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\simplexml} (#36204). php-src: ext/simplexml/sxe.c
 */
final class SimpleXMLElementOffsetGet implements Call
{
    public function __construct(private string $method = 'offsetGet')
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $hooks = $context->extensionLowering->requireSimpleXml();
        if ('offsetUnset' === $this->method) {
            return $hooks->offsetUnset($context, ...$args);
        }
        if ('offsetSet' === $this->method) {
            return $hooks->offsetSet($context, ...$args);
        }
        if ('offsetExists' === $this->method) {
            return $hooks->offsetExists($context, ...$args);
        }

        return $hooks->offsetGet($context, ...$args);
    }
}
