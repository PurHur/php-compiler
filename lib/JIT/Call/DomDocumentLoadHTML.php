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

        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            BasicBlockHelper::branchToFreshContinue($context, 'dom_lh_invoke');
        }

        $result = JitDomLoadHTML::invoke($context, ...$args);

        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            $mainCont = BasicBlockHelper::append($context, 'main_cont_after_dom_lh');
            $context->builder->branch($mainCont);
            $context->builder->positionAtEnd($mainCont);
        }

        return $result;
    }
}
