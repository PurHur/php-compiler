<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Compiler-internal: reinitialize a clone-with listed property to its default (#10310).
 *
 * @see Zend/zend_clones.c — clone($obj, ['prop']) reinit vs explicit values
 */
final class phpc_clone_with_reinit extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_clone_with_reinit');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('phpc_clone_with_reinit() requires exactly two arguments');
        }
        $objVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objVar->type) {
            return;
        }
        $propName = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'phpc_clone_with_reinit',
            1,
            'property'
        );
        $vm = $frame->vmContext?->runtime->vm;
        if (!$vm instanceof VM) {
            throw new \LogicException('phpc_clone_with_reinit() requires an active VM');
        }
        $vm->reinitCloneWithProperty($objVar->toObject(), $propName);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitCloneWithReinit::reinit($context, ...$args);
    }
}
