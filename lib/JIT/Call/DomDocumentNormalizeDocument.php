<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomNormalize;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** DOMDocument::normalizeDocument() — user-script AOT (#20642). */
final class DomDocumentNormalizeDocument implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_normalize_document_invoke_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMDocument::normalizeDocument',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return JitDomNormalize::invokeDocument($context, ...$args);
    }
}
