<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\XslExtensionHooks;
use PHPLLVM\Value;

/**
 * xsl surfaces for lib/JIT Call XsltMethod (#36204).
 *
 * php-src: ext/xsl/php_xsl.c — XSLTProcessor security/EXSLT methods.
 * Registered from {@see Module::jitInit} so Call files do not import ext/xsl.
 */
final class JitXslExtensionHooksFacade implements XslExtensionHooks
{
    public function invoke(Context $context, string $methodLc, JITVariable ...$args): Value
    {
        return JitXsltMethod::invoke($context, $methodLc, ...$args);
    }
}
