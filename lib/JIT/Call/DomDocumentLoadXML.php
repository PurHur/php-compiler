<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomLoadXML;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomLoadXMLRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::loadXML() — user-script AOT (#18268). */
final class DomDocumentLoadXML implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // Skip ABI link for compile-time null/empty — ValueError is emitted in IR (#22680).
        $source = $args[1] ?? null;
        $isNullOrEmpty = null !== $source && (
            Variable::TYPE_NULL === $source->type
            || $source->isNullConstant
            || '' === (JitStringBuiltinArg::compileTimeLiteral($source) ?? $source->compileTimeString ?? null)
        );
        if (!$isNullOrEmpty && !JitDomDocumentMethodKernel::shouldUse($context)) {
            DomLoadXMLRuntime::ensureLinked($context);
        }

        return JitDomLoadXML::invoke($context, ...$args);
    }
}
