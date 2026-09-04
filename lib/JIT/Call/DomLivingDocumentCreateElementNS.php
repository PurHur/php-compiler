<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Dom\HTMLDocument / XMLDocument / Document::createElementNS() — user-script AOT (#28958).
 *
 * Main-module materialize; HTMLDocument + HTML ns → Dom\HTMLElement (#21030).
 */
final class DomLivingDocumentCreateElementNS implements Call
{
    public function __construct(
        private bool $htmlDocument,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'livingDocument.createElementNS',
            ...$args
        );
    }
}
