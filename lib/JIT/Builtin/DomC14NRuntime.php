<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;

/** JIT/AOT link for DOMNode::C14N() via DomC14NJitHelper (#19467, #22378, #32962). */
final class DomC14NRuntime
{
    public const ABI_NAME = '__phpc_dom_c14n';

    public static function ensureLinked(Context $context): void
    {
        // Always use the Context + ?string→value bridge (null = relative-NS false).
        // Prelinked Variable-return unit.o bitcast to __value__* and echoed as Object (#32962).
        JitDomDocumentMethodKernel::ensureC14NBridge($context);
    }
}
