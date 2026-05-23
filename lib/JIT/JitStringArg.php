<?php

declare(strict_types=1);

/**
 * Lower string/path builtin arguments to {@see __string__*} for LLVM calls.
 *
 * Self-host bundle code often passes concat/__DIR__ paths as boxed {@see Variable::TYPE_VALUE}
 * (string tag) rather than {@see Variable::TYPE_STRING}.
 */

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

final class JitStringArg
{
    /**
     * @return Value {@see __string__*}
     */
    public static function lower(Context $context, Variable $arg, string $contextLabel = 'argument'): Value
    {
        $literal = self::compileTimeLiteral($arg);
        if (null !== $literal) {
            return $context->builder->load($context->constantStringFromString($literal));
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException("{$contextLabel} must be a string in this compiler build");
    }

    public static function compileTimeLiteral(Variable $arg): ?string
    {
        return $arg->compileTimeString ?? null;
    }
}
