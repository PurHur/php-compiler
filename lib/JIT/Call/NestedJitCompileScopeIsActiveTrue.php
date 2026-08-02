<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Fold NestedJitCompileScope::isActive() → true while NestedJIT-compiling helpers (#26773).
 *
 * Without this, the isActive() check is emitted as a runtime call that returns false
 * under AOT-linked helpers, so VmPasswordPure takes the host {@see \crypt()} branch
 * which NestedJITs into PasswordJitHelper (null password_hash).
 */
final class NestedJitCompileScopeIsActiveTrue implements Call
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        return $context->constantFromBool(true);
    }
}
