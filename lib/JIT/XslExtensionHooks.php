<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * xsl extension surfaces needed by lib/JIT Call (#36204).
 *
 * Implemented in {@code ext/xsl/JitXslExtensionHooksFacade.php}; Call
 * XsltMethod must not import {@code ext\xsl}.
 */
interface XslExtensionHooks
{
    /** XSLTProcessor instance method thin-AOT dispatch. */
    public function invoke(Context $context, string $methodLc, Variable ...$args): Value;
}
