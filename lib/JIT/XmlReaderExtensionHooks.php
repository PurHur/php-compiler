<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * xmlreader extension surfaces needed by lib/JIT Call (#36204).
 *
 * Implemented in {@code ext/xmlreader/JitXmlReaderExtensionHooksFacade.php}; Call
 * XmlReader* files must not import {@code ext\xmlreader}.
 */
interface XmlReaderExtensionHooks
{
    /** XMLReader instance / static method thin-AOT dispatch. */
    public function invoke(Context $context, string $methodLc, Variable ...$args): Value;
}
