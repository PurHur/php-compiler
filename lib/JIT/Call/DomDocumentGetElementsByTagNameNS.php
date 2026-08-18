<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomGetElementsByTagName;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** DOMDocument::getElementsByTagNameNS() — user-script AOT (#32415, php-src php_dom.c). */
final class DomDocumentGetElementsByTagNameNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMDocument::getElementsByTagNameNS',
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_gebtns_invoke_cont');

        return JitDomGetElementsByTagName::invokeNS($context, ...$args);
    }
}
