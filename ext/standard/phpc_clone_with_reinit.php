<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\CloneWithSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Compiler-internal: open clone-with readonly reinit window (#7250).
 *
 * Emitted by {@see \PHPCompiler\Ast\CloneWithDesugar}; not a public PHP API.
 */
final class phpc_clone_with_reinit extends Internal
{
    public function __construct()
    {
        parent::__construct('__phpc_clone_with_reinit');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('__phpc_clone_with_reinit() requires exactly two arguments');
        }
        $objectVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \LogicException('__phpc_clone_with_reinit() argument #1 must be an object');
        }
        $listVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $listVar->type) {
            throw new \LogicException('__phpc_clone_with_reinit() argument #2 must be an array');
        }
        $names = [];
        foreach ($listVar->toArray()->iterate(true) as $value) {
            if (Variable::TYPE_STRING !== $value->type) {
                continue;
            }
            $names[] = $value->toString();
        }
        CloneWithSupport::markReinitable($objectVar->toObject(), $names);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('__phpc_clone_with_reinit() is VM-only in this compiler build (#7250)');
    }
}
