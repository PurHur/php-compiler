<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * ob_get_status() — output buffer metadata (VM; ext/standard/output.c, issue #3647).
 */
final class ob_get_status extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_get_status');
    }

    public function execute(Frame $frame): void
    {
        $full = false;
        if (\count($frame->calledArgs) > 0) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $arg->type && Variable::TYPE_INTEGER !== $arg->type) {
                throw new \LogicException('ob_get_status() expects bool full_status');
            }
            $full = $arg->toBool();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmOb::getStatus($full));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ob_get_status() is VM-only in this compiler build');
    }
}
