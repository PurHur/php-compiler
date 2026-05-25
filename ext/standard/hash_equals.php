<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** hash_equals() — timing-safe string compare (VM + JIT/AOT via __compiler_hash_equals, issue #2179). */
final class hash_equals extends Internal
{
    public function __construct()
    {
        parent::__construct('hash_equals');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('hash_equals() requires exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $known = $frame->calledArgs[0]->resolveIndirect();
        $user = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $known->type || Variable::TYPE_STRING !== $user->type) {
            throw new \LogicException('hash_equals() requires two strings in this compiler build');
        }
        $frame->returnVar->bool(VmHash::equals($known->toString(), $user->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('hash_equals() requires exactly two arguments in this compiler build');
        }

        return JitHash::equals(
            $context,
            $this->jitString($context, $args[0], 'hash_equals() argument #1'),
            $this->jitString($context, $args[1], 'hash_equals() argument #2')
        );
    }
}
