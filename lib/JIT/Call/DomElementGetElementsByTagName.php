<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomGetElementsByTagName;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** DOMElement::getElementsByTagName() — user-script AOT (#32454, ext/dom/element.c). */
final class DomElementGetElementsByTagName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMElement::getElementsByTagName',
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return JitDomGetElementsByTagName::invokeFromElement($context, ...$args);
    }
}
