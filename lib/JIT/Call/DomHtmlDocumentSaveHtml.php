<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomHtmlDocumentSaveHtml;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Dom\HTMLDocument::saveHtml() — user-script AOT fold (#31324).
 *
 * Avoids NestedJIT DomInstanceMethod abort on thin createFromString docs.
 */
final class DomHtmlDocumentSaveHtml implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'Dom\\HTMLDocument::saveHtml';

    /** @var list<string> php-src ext/dom/php_dom.stub.php */
    public array $paramNames = ['node'];

    /** Instance method — receiver precedes named optionals. */
    public int $namedArgsReceiverPrefix = 1;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDomHtmlDocumentSaveHtml::invoke($context, ...$args);
    }
}
