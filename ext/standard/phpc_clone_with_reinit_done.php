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
 * Compiler-internal: close clone-with readonly reinit window (#7250).
 */
final class phpc_clone_with_reinit_done extends Internal
{
    public function __construct()
    {
        parent::__construct('__phpc_clone_with_reinit_done');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('__phpc_clone_with_reinit_done() requires exactly one argument');
        }
        $objectVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \LogicException('__phpc_clone_with_reinit_done() argument #1 must be an object');
        }
        CloneWithSupport::clearReinitable($objectVar->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('__phpc_clone_with_reinit_done() is VM-only in this compiler build (#7250)');
    }
}
