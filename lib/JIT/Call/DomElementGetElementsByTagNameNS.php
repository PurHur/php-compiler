<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomGetElementsByTagName;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** DOMElement::getElementsByTagNameNS() — user-script AOT (#32511, ext/dom/element.c). */
final class DomElementGetElementsByTagNameNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMElement::getElementsByTagNameNS',
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_el_gebtns_invoke_cont');

        return JitDomGetElementsByTagName::invokeFromElementNS($context, ...$args);
    }
}
