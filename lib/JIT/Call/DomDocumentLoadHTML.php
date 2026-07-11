<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomLoadHTML;
use PHPCompiler\JIT\Builtin\DomDocumentMethodUserScriptLlvm;
use PHPCompiler\JIT\Builtin\DomLoadHTMLRuntime;
use PHPCompiler\JIT\Builtin\DomSyncElementIdMapRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::loadHTML() — user-script AOT (#17954). */
final class DomDocumentLoadHTML implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $savedVariables = DomDocumentMethodUserScriptLlvm::shouldUse($context)
            ? self::cloneVariableStorage($context->scope->variables)
            : null;

        DomLoadHTMLRuntime::ensureLinked($context);
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            DomSyncElementIdMapRuntime::ensureLinked($context);
        }

        $result = JitDomLoadHTML::invoke($context, ...$args);

        if (null !== $savedVariables) {
            $context->scope->variables = self::cloneVariableStorage($savedVariables);
            NestedJitCompileScope::resyncNamedBindings($context);
        }

        return $result;
    }

    private static function cloneVariableStorage(\SplObjectStorage $storage): \SplObjectStorage
    {
        $clone = new \SplObjectStorage();
        foreach ($storage as $op) {
            $clone[$op] = $storage[$op];
        }

        return $clone;
    }
}
