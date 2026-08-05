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

/** DOMXPath::registerNamespace() — user-script AOT (#27575). */
final class DomXPathRegisterNamespace implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xpath_register_namespace_cont');
        if (\count($args) < 3) {
            throw new \LogicException('DOMXPath::registerNamespace() expects receiver, prefix, and URI');
        }
        if (JitDomXPathRegisterUserScript::shouldUse($context)) {
            $us = JitDomXPathRegisterUserScript::tryRegisterNamespace($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }
        $extra = \array_slice($args, 1);

        return DomInstanceMethodRuntime::invoke(
            $context,
            \count($extra),
            'registernamespace',
            $args[0],
            ...$extra
        );
    }
}
