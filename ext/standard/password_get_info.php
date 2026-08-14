<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** password_get_info() — hash metadata (ext/standard/password.c, issue #3649). */
final class password_get_info extends Internal
{
    public function __construct()
    {
        parent::__construct('password_get_info');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/password.c — ArgumentCountError (#30712).
        $this->requireExactArgCount($frame, 'password_get_info', 1);
        // Z_PARAM_STR — soft-null DEP+coerce on PROFILE=8.4 (#21537, reverts #20672; password.c).
        $hash = VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            'password_get_info',
            0,
            'hash'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(
            VmPassword::infoToHashTable(VmPassword::getInfo($hash))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #30712.
        if (!$this->requireExactJitArgCount($context, $args, 'password_get_info', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitPasswordGetInfo::invoke(
            $context,
            // Z_PARAM_STR — soft-null DEP+coerce on PROFILE=8.4 (#21537, reverts #20672; password.c).
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'password_get_info', 0, 'hash')
        );
    }
}
