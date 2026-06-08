<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmHttpResponse;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Compile-time ResponseCode lowering for http_response_code() (#7322).
 */
final class HttpResponseCodeJit
{
    public static function compileTimeCodeLong(Context $context, JITVariable $arg): ?int
    {
        if (null === $arg->compileTimeConstantName || null === $context->runtime->vmContext) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
        if (null === $phpVar) {
            return null;
        }
        $fromEnum = VmHttpResponse::tryResponseCodeInt($phpVar);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $phpVar->type) {
            return $phpVar->toInt();
        }

        return null;
    }
}
