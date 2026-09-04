<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * XSLTProcessor security/EXSLT methods — user-script AOT via host ext/xsl (#20392).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\xsl} (#36204). php-src: ext/xsl/php_xsl.c
 */
final class XsltMethod implements Call
{
    public function __construct(
        private readonly string $methodLc,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireXsl()->invoke($context, $this->methodLc, ...$args);
    }
}
