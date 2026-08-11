<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ObGzhandler;
use PHPCompiler\JIT\Builtin\ObOutputRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_start() (issue #118, #1056, #8818, #30121). */
final class JitObStart
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        ObOutputRuntime::ensureObStackLinked($context);

        if (1 === \count($args)) {
            $callback = $args[0];
            // php-src `?callable $callback = null` — null is equivalent to omitted (#30121).
            if (JITVariable::TYPE_NULL === $callback->type || $callback->isNullConstant) {
                return self::startPlain($context);
            }
            $literal = JitStringArg::compileTimeLiteral($callback);
            if ('ob_gzhandler' === $literal) {
                ObGzhandler::ensureLinked($context);
                $context->builder->call($context->lookupFunction('__phpc_ob_start_with_gzhandler'));

                return $context->constantFromBool(true);
            }
            throw new \LogicException(
                'ob_start() callback "'.$literal.'" not supported in this compiler build; only ob_gzhandler is implemented for JIT'
            );
        }
        if (\count($args) > 1) {
            throw new \LogicException('ob_start() accepts at most one callback argument in this compiler build');
        }

        return self::startPlain($context);
    }

    private static function startPlain(Context $context): Value
    {
        $context->builder->call($context->lookupFunction('__phpc_ob_start'));

        return $context->constantFromBool(true);
    }
}
