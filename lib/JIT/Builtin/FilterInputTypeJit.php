<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\filter\VmFilter;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Compile-time PhpInputFilter lowering for filter_input() (#7284).
 */
final class FilterInputTypeJit
{
    public static function compileTimeInputType(Context $context, JITVariable $arg): ?int
    {
        if (null === $arg->compileTimeConstantName || null === $context->runtime->vmContext) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
        if (null === $phpVar) {
            return null;
        }
        $fromEnum = VmFilter::tryPhpInputFilterInt($phpVar);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $phpVar->type) {
            return $phpVar->toInt();
        }

        return null;
    }
}
