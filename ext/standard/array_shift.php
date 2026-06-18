<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitReferencableCheck;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_shift() for packed list arrays (subset of PHP).
 */
final class array_shift extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_shift() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_shift() argument must be an array in this compiler build');
        }
        $ht = $array->toArray();
        if (0 === $ht->getNumElements() && null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                'array_shift(): Trying to shift an empty array',
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
        }
        $shifted = $ht->shiftFirst();
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $shifted) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom($shifted);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('array_shift() requires exactly one argument');
        }
        if (!JitReferencableCheck::guardArrayMutatorByRefArg($context, 'array_shift', $args[0])) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_shift() argument #'.((int) $i + 1));
            }
        }
        return ArrayBuiltinHelper::shiftFirst($context, $args[0]);
    }
}
