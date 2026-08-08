<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** password_verify() — VM/JIT/AOT via VmPasswordNative libcrypt (issues #172, #4794, #6906). */
final class password_verify extends Internal
{
    public function __construct()
    {
        parent::__construct('password_verify');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/password.c — ArgumentCountError (#28476).
        $this->requireExactArgCount($frame, 'password_verify', 2);
        // Z_PARAM_STR — soft-null DEP+coerce on PROFILE=8.4 (#21314, ext/standard/password.c).
        $password = VmString::trimFamilyStringArgForFrame($frame, 0, 'password_verify', 0, 'password');
        $hash = VmString::trimFamilyStringArgForFrame($frame, 1, 'password_verify', 1, 'hash');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            VmPassword::verify($password, $hash)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #28476.
        if (!$this->requireExactJitArgCount($context, $args, 'password_verify', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        return JitPassword::verify(
            $context,
            // Z_PARAM_STR — soft-null DEP+coerce on PROFILE=8.4 (#21314, ext/standard/password.c).
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'password_verify', 0, 'password'),
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'password_verify', 1, 'hash')
        );
    }
}
