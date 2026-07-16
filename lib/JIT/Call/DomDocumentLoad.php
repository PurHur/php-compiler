<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomLoad;
use PHPCompiler\ext\dom\JitDomLoadUserScript;
use PHPCompiler\ext\dom\JitDomLoadXMLUserScript;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomLoadRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMDocument::load() — user-script AOT (#18897). */
final class DomDocumentLoad implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (JitDomDocumentMethodKernel::shouldUse($context) && isset($args[1])) {
            $path = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null !== $path && '' !== trim($path)) {
                $xml = @\file_get_contents($path);
                if (false !== $xml && '' !== trim($xml)) {
                    JitDomLoadXMLUserScript::rememberCompileTimeXml($xml);
                }
            }
        }

        if (!JitDomDocumentMethodKernel::shouldUse($context)) {
            DomLoadRuntime::ensureLinked($context);
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            BasicBlockHelper::branchToFreshContinue($context, 'dom_load_invoke');
        }

        $result = JitDomLoad::invoke($context, ...$args);

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $mainCont = BasicBlockHelper::append($context, 'main_cont_after_dom_load');
            $context->builder->branch($mainCont);
            $context->builder->positionAtEnd($mainCont);
        }

        return $result;
    }
}
