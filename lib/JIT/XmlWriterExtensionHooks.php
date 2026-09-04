<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * xmlwriter extension surfaces needed by lib/JIT Call (#36204).
 *
 * Implemented in {@code ext/xmlwriter/JitXmlWriterExtensionHooksFacade.php}; Call
 * XmlWriter* files must not import {@code ext\xmlwriter}.
 */
interface XmlWriterExtensionHooks
{
    /** XMLWriter instance / static method thin-AOT dispatch. */
    public function invoke(Context $context, string $methodLc, Variable ...$args): Value;
}
