<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomXPathRegisterUserScript;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomInstanceMethodRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMXPath::registerPhpFunctions() — user-script AOT (#27575). */
final class DomXPathRegisterPhpFunctions implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xpath_register_php_functions_cont');
        if ([] === $args) {
            throw new \LogicException('DOMXPath::registerPhpFunctions() called without $this');
        }
        if (JitDomXPathRegisterUserScript::shouldUse($context)) {
            $us = JitDomXPathRegisterUserScript::tryRegisterPhpFunctions($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }
        $extra = \array_slice($args, 1);

        return DomInstanceMethodRuntime::invoke(
            $context,
            \count($extra),
            'registerphpfunctions',
            $args[0],
            ...$extra
        );
    }
}
