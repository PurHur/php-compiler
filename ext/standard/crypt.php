<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * crypt() — POSIX DES/BCRYPT via libcrypt (issue #3771; php-src: ext/standard/crypt.c).
 *
 * Soft-null $string/$salt on forward profile — Zend 8.4 deprecate+coerce (#21280).
 * NestedJIT leaf: {@see JitLibcryptKernel} so `@crypt` does not re-enter
 * {@see PasswordJitHelper} via `__compiler_crypt` (#29545 / #29531 shape).
 */
final class crypt extends Internal
{
    public function __construct()
    {
        parent::__construct('crypt');
    }

    public function execute(Frame $frame): void
    {
        // php-src: crypt(string $string, string $salt) — ArgumentCountError (#20975).
        $this->requireExactArgCount($frame, 'crypt', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $password = VmString::trimFamilyStringArgForFrame($frame, 0, 'crypt', 0, 'string');
        $salt = VmString::trimFamilyStringArgForFrame($frame, 1, 'crypt', 1, 'salt');
        $frame->returnVar->string(
            VmPassword::crypt($password, $salt)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'crypt', 2)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $password = self::jitStringArg($context, $args[0], 0, 'string');
        $salt = self::jitStringArg($context, $args[1], 1, 'salt');
        if (NestedJitCompileScope::isActive()) {
            return JitLibcryptKernel::invoke($context, $password, $salt);
        }

        return JitPassword::crypt($context, $password, $salt);
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'crypt',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'crypt',
            $argIndex,
            $paramName
        );
    }
}
