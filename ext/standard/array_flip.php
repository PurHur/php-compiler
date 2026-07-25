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
use PHPCompiler\JIT\Builtin\ArrayFlipRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_flip() for arrays with int or string keys and values (subset of PHP; JIT via ArrayFlipRuntime).
 *
 * VM: {@see VmArray::flip()}; JIT/AOT: {@see ArrayFlipRuntime::flip()}.
 */
final class array_flip extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/array.c — ArgumentCountError (#23165).
        $this->requireExactArgCount($frame, 'array_flip', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArrayParamForCaller($frame, $frame->calledArgs[0], 'array_flip', 1, 'array');
        $frame->returnVar->array(VmArray::flip($ht, $frame));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        // php-src ext/standard/array.c — ArgumentCountError (#23165).
        if (!$this->requireExactJitArgCount($context, $args, 'array_flip', 1)) {
            return HashTableHelper::emptyVariable($context)->value;
        }
        TypeErrorRaise::ensureLinked($context);
        // php-src 8.0+: Z_PARAM_ARRAY — always TypeError on null (#21916, re-#21771).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            JitArrayElem::requireArrayParam($context, $args[0], 'array_flip', 1, 'array');

            return HashTableHelper::emptyVariable($context)->value;
        }
        JitArrayElem::requireArrayParam($context, $args[0], 'array_flip', 1, 'array');

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_flip() argument #'.((int) $i + 1));
            }
        }
        TypeErrorRaise::ensureLinked($context);

        return ArrayFlipRuntime::flip($context, $args[0]);
    }
}
