<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomCreateElement;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Dom\HTMLDocument / XMLDocument / Document::createElement() — user-script AOT (#28958).
 *
 * Main-module materialize with the living element class (NestedJIT helper segfaults on
 * createFromString / createEmpty docs without DomRegistry).
 */
final class DomLivingDocumentCreateElement implements Call
{
    public function __construct(
        private string $elementClass,
        private bool $htmlUppercase,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomCreateElement::invokeLiving(
            $context,
            $this->elementClass,
            $this->htmlUppercase,
            ...$args
        );
    }
}
