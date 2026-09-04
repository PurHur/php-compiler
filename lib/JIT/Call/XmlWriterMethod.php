<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** XMLWriter instance methods — user-script AOT via host xmlwriter (#19551).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\xmlwriter} (#36204). php-src: ext/xmlwriter/php_xmlwriter.c
 */
final class XmlWriterMethod implements Call
{
    public function __construct(
        private readonly string $methodLc,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireXmlWriter()->invoke($context, $this->methodLc, ...$args);
    }
}
