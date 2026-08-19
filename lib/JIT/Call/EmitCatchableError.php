<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Invoke-time Error for try/catch — phpc_jit_set_throw_pending (#31915 / #31968). */
final class EmitCatchableError implements Call
{
    public function __construct(
        private readonly string $message,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        TryCatchHelper::emitPendErrorForCaller($context, $this->message);

        return JitValueBox::alloc($context);
    }
}
