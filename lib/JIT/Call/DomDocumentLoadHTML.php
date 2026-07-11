<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\DomParseSimpleHtmlJitHelper;
use PHPCompiler\ext\dom\JitDomLoadHTML;
use PHPCompiler\ext\dom\JitDomLoadHTMLUserScript;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomDocumentMethodUserScriptLlvm;
use PHPCompiler\JIT\Builtin\DomLoadHTMLRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::loadHTML() — user-script AOT (#17954). */
final class DomDocumentLoadHTML implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context) && isset($args[1])) {
            $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null !== $lit) {
                $parsed = DomParseSimpleHtmlJitHelper::parseArgv($lit);
                if (null !== $parsed) {
                    JitDomLoadHTMLUserScript::rememberCompileTimeParsed($parsed);
                }
            }
        }

        if (!DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            DomLoadHTMLRuntime::ensureLinked($context);
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_lh_call_cont');

        return JitDomLoadHTML::invoke($context, ...$args);
    }
}
