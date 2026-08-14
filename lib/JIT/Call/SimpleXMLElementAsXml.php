<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlAsXml;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** SimpleXMLElement::asXML() / saveXML() — user-script AOT (#19306, #30828). */
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

        return JitSimpleXmlAsXml::invoke($context, ...$args);
    }
}
