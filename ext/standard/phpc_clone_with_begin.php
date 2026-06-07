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
 * @see Zend/zend_objects.c zend_objects_clone_obj_with()
 */
final class phpc_clone_with_begin extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_clone_with_begin');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('phpc_clone_with_begin() requires at least one argument');
        }
        $objVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objVar->type) {
            return;
        }
        $names = [];
        for ($i = 1; $i < $argc; ++$i) {
            $names[] = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[$i],
                'phpc_clone_with_begin',
                $i,
                'property'
            );
        }
        CloneWithSupport::beginReinit($objVar->toObject(), $names);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitCloneWithReinit::begin($context, ...$args);
    }
}
