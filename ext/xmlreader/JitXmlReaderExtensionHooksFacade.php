<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\XmlReaderExtensionHooks;
use PHPLLVM\Value;

/**
 * xmlreader surfaces for lib/JIT Call XmlReader* (#36204).
 *
 * php-src: ext/xmlreader/php_xmlreader.c — XMLReader construct + method thin-AOT.
 * Registered from {@see Module::jitInit} so Call files do not import ext/xmlreader.
 */
final class JitXmlReaderExtensionHooksFacade implements XmlReaderExtensionHooks
{
    public function invoke(Context $context, string $methodLc, JITVariable ...$args): Value
    {
        return JitXmlReaderMethod::invoke($context, $methodLc, ...$args);
    }
}
