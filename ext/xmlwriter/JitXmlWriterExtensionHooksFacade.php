<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\XmlWriterExtensionHooks;
use PHPLLVM\Value;

/**
 * xmlwriter surfaces for lib/JIT Call XmlWriter* (#36204).
 *
 * php-src: ext/xmlwriter/php_xmlwriter.c — XMLWriter construct + method thin-AOT.
 * Registered from {@see Module::jitInit} so Call files do not import ext/xmlwriter.
 */
final class JitXmlWriterExtensionHooksFacade implements XmlWriterExtensionHooks
{
    public function invoke(Context $context, string $methodLc, JITVariable ...$args): Value
    {
        return JitXmlWriterMethod::invoke($context, $methodLc, ...$args);
    }
}
