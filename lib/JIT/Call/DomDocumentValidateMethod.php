<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\DomDocumentValidateRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument validation / xinclude / registerNodeClass — user-script AOT (#35540). */
final class DomDocumentValidateMethod implements Call
{
    public function __construct(private readonly string $methodLc)
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return match ($this->methodLc) {
            'validate' => DomDocumentValidateRuntime::invokeValidate($context, ...$args),
            'schemavalidate' => DomDocumentValidateRuntime::invokeSchemaValidate($context, ...$args),
            'schemavalidatesource' => DomDocumentValidateRuntime::invokeSchemaValidateSource($context, ...$args),
            'relaxngvalidate' => DomDocumentValidateRuntime::invokeRelaxNGValidate($context, ...$args),
            'relaxngvalidatesource' => DomDocumentValidateRuntime::invokeRelaxNGValidateSource($context, ...$args),
            'xinclude' => DomDocumentValidateRuntime::invokeXInclude($context, ...$args),
            'registernodeclass' => DomDocumentValidateRuntime::invokeRegisterNodeClass($context, ...$args),
            default => throw new \LogicException('DOMDocument::'.$this->methodLc.'() AOT dispatch missing (#35540)'),
        };
    }
}
