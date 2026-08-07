<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * hash_equals() — timing-safe string compare (VM + JIT/AOT via __compiler_hash_equals, issue #2179).
 *
 * Excess argc → ArgumentCountError (#28315).
 */
final class hash_equals extends Internal
{
    public function __construct()
    {
        parent::__construct('hash_equals');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/hash/hash.stub.php — ArgumentCountError (#28315).
        $this->requireExactArgCount($frame, 'hash_equals', 2);
        $known = VmString::requireStringBuiltinArg($frame->calledArgs[0], 'hash_equals', 0, 'known_string');
        $user = VmString::requireStringBuiltinArg($frame->calledArgs[1], 'hash_equals', 1, 'user_string');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmHash::equals($known, $user));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // Catchable ArgumentCountError (AOT try/catch) — peer md5 #28313 / #28315.
        if (2 !== $argc) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('hash_equals() expects exactly 2 arguments, %d given', $argc)
            );

            return $slot;
        }

        return JitHash::equals(
            $context,
            JitStringBuiltinArg::lowerRequiredString($context, $args[0], 'hash_equals', 0, 'known_string'),
            JitStringBuiltinArg::lowerRequiredString($context, $args[1], 'hash_equals', 1, 'user_string')
        );
    }
}
