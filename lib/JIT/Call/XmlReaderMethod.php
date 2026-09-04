<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** XMLReader instance methods — user-script AOT (#27299).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\xmlreader} (#36204). php-src: ext/xmlreader/php_xmlreader.c
 */
final class XmlReaderMethod implements Call
{
    public function __construct(
        private readonly string $methodLc,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireXmlReader()->invoke($context, $this->methodLc, ...$args);
    }
}
