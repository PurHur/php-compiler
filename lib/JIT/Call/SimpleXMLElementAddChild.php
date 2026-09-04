<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * SimpleXMLElement::addChild() / addAttribute() — user-script AOT (#19306, #35806).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\simplexml} (#36204). php-src: ext/simplexml/sxe.c
 */
final class SimpleXMLElementAddChild implements Call
{
    public function __construct(private string $name = 'addChild')
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $hooks = $context->extensionLowering->requireSimpleXml();
        if ('addAttribute' === $this->name) {
            return $hooks->addAttribute($context, ...$args);
        }

        return $hooks->addChild($context, ...$args);
    }
}
