<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** password_needs_rehash() — VM via host PHP; JIT/AOT via libcrypt runtime (issue #3279). */
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
        if (null === $frame->returnVar) {
            return;
        }
        $hash = $frame->calledArgs[0]->resolveIndirect();
        $algo = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $hash->type) {
            throw new \LogicException('password_needs_rehash() requires a string hash in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $algo->type) {
            throw new \LogicException('password_needs_rehash() requires an integer algorithm in this compiler build');
        }
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
        $frame->returnVar->bool(
            VmPassword::needsRehash($hash->toString(), $algo->toInt(), $options)
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
            $this->jitString($context, $args[0], 'password_needs_rehash() hash'),
            $args[1],
            $options
        );
    }
}
