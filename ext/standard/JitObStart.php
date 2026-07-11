<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ObGzhandler;
use PHPCompiler\JIT\Builtin\ObOutputRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_start() (issue #118, #1056, #8818). */
final class JitObStart
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        ObOutputRuntime::ensureObStackLinked($context);

        if (1 === \count($args)) {
            $literal = JitStringArg::compileTimeLiteral($args[0]);
            if ('ob_gzhandler' === $literal) {
                ObGzhandler::ensureLinked($context);
                $context->builder->call($context->lookupFunction('__phpc_ob_start_with_gzhandler'));

                return $context->getTypeFromString('int32')->constInt(0, false);
            }
            throw new \LogicException(
                'ob_start() callback "'.$literal.'" not supported in this compiler build; only ob_gzhandler is implemented for JIT'
            );
        }
        if (\count($args) > 1) {
            throw new \LogicException('ob_start() accepts at most one callback argument in this compiler build');
        }
        $context->builder->call($context->lookupFunction('__phpc_ob_start'));

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
