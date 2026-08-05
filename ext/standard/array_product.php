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
use PHPCompiler\JIT\Builtin\ArrayProductRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_product() for arrays of integers and floats (ext/standard/array.c subset).
 *
 * VM/JIT SSOT: {@see ArrayProductJitHelper}
 */
final class array_product extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/array.c — ArgumentCountError (#23165).
        $this->requireExactArgCount($frame, 'array_product', 1);
        $ht = VmArray::requireArray($frame->calledArgs[0]->resolveIndirect(), 'array_product');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(ArrayProductJitHelper::product($ht));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        ExceptionBridge::ensureLinked($context);
        TypeErrorRaise::ensureLinked($context);
        // php-src ext/standard/array.c — ArgumentCountError (#23165).
        if (!$this->requireExactJitArgCount($context, $args, 'array_product', 1)) {
            return $context->getTypeFromString('int64')->constInt(1, false);
        }
        // php-src Z_PARAM_ARRAY — catchable TypeError under AOT try/catch (#27483; peer #27479).
        // Always via JitArrayElem → ExceptionBridge (not bare TypeErrorRaise::emitRaise).
        JitArrayElem::requireArrayArg($context, $args[0], 'array_product');
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_product() argument #'.((int) $i + 1));
            }
        }
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY
            || JITVariable::TYPE_HASHTABLE === $args[0]->type
            || JITVariable::TYPE_VALUE === $args[0]->type
        ) {
            return ArrayProductRuntime::product($context, $args[0]);
        }

        // Static non-array types already raised above; poison return for SSA (empty product = 1).
        return $context->getTypeFromString('int64')->constInt(1, false);
    }
}
