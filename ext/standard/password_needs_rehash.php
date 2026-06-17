<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** password_needs_rehash() — VM via VmPassword; JIT/AOT via StringPasswordCryptoJit (issue #3279, #6503, #6906). */
final class password_needs_rehash extends Internal
{
    public function __construct()
    {
        parent::__construct('password_needs_rehash');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('password_needs_rehash() requires two or three arguments');
        }
        $hash = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'password_needs_rehash', 0, 'hash');
        $algo = VmPassword::resolveAlgo($frame->calledArgs[1], 'password_needs_rehash', 1, 'algo');
        $options = [];
        if (3 === $argc) {
            $optVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $optVar->type) {
                throw new \LogicException('password_needs_rehash() options must be an array in this compiler build');
            }
            $exported = VmJson::export($optVar);
            if (!\is_array($exported)) {
                throw new \LogicException('password_needs_rehash() options must be an array in this compiler build');
            }
            $options = $exported;
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            VmPassword::needsRehash($hash, $algo, $options)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('password_needs_rehash() requires two or three arguments');
        }
        $options = null;
        if (3 === $argc) {
            $options = $args[2];
        }

        return JitPasswordNeedsRehash::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'password_needs_rehash', 0, 'hash'),
            $args[1],
            $options
        );
    }
}
