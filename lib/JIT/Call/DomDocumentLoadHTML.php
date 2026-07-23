<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\DomParseSimpleHtmlJitHelper;
use PHPCompiler\ext\dom\JitDomLoadHTML;
use PHPCompiler\ext\dom\JitDomLoadHTMLUserScript;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
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
        $source = $args[1] ?? null;
        $isNullOrEmpty = null !== $source && (
            Variable::TYPE_NULL === $source->type
            || $source->isNullConstant
            || '' === (JitStringBuiltinArg::compileTimeLiteral($source) ?? $source->compileTimeString ?? null)
        );

        if (JitDomDocumentMethodKernel::shouldUse($context) && isset($args[1]) && !$isNullOrEmpty) {
            JitDomLoadHTMLUserScript::rememberCompileTimeOptions($context, $args[2] ?? null);
            $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null !== $lit) {
                $parsed = DomParseSimpleHtmlJitHelper::parseArgv($lit);
                if (null !== $parsed) {
                    JitDomLoadHTMLUserScript::rememberCompileTimeParsed($parsed);
                }
            }
        }

        // Skip ABI link / fresh-continue for compile-time null/empty — ValueError IR only (#22680).
        if (!$isNullOrEmpty && !JitDomDocumentMethodKernel::shouldUse($context)) {
            DomLoadHTMLRuntime::ensureLinked($context);
        }

        if (!$isNullOrEmpty && JitDomDocumentMethodKernel::shouldUse($context)) {
            BasicBlockHelper::branchToFreshContinue($context, 'dom_lh_invoke');
        }

        $result = JitDomLoadHTML::invoke($context, ...$args);

        // Catchable ValueError leaves the insert block terminated (branch to try dispatch).
        // Do not stitch a reachable main_cont — that would run post-call try-body code (#22680).
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $insert = BasicBlockHelper::tryGetInsertBlock($context);
            if (null !== $insert && null === $insert->getTerminator()) {
                $mainCont = BasicBlockHelper::append($context, 'main_cont_after_dom_lh');
                $context->builder->branch($mainCont);
                $context->builder->positionAtEnd($mainCont);
            }
        }

        return $result;
    }
}
