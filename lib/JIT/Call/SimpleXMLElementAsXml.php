<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * SimpleXMLElement::asXML() / saveXML() — user-script AOT (#19306, #30828).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\simplexml} (#36204). php-src: ext/simplexml/sxe.c
 */
final class SimpleXMLElementAsXml implements Call
{
    public function __construct(private string $method = 'asXML')
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'SimpleXMLElement::'.$this->method,
            0,
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return $context->extensionLowering->requireSimpleXml()->asXml($context, ...$args);
    }
}
