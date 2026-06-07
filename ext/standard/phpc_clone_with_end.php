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

/** Compiler-internal: close clone-with readonly reinit window (#7250). */
final class phpc_clone_with_end extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_clone_with_end');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('phpc_clone_with_end() requires exactly one argument');
        }
        $objVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objVar->type) {
            return;
        }
        CloneWithSupport::endReinit($objVar->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitCloneWithReinit::end($context, ...$args);
    }
}
