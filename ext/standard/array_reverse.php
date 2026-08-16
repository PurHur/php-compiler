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
use PHPCompiler\JIT\Builtin\ArrayReverseRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_reverse() — packed lists and associative arrays (ext/standard/array.c; #4335).
 *
 * Null $preserve_keys: Z_PARAM_BOOL — strict TypeError; else DEP+coerce (#31442, re-#24693).
 */
final class array_reverse extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/array.c — ArgumentCountError (#23165).
        $this->requireArgCountRange($frame, 'array_reverse', 1, 2);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArrayParam($frame->calledArgs[0], 'array_reverse', 1, 'array');
        $preserveKeys = false;
        if (2 === $argc) {
            // Z_PARAM_BOOL: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31442).
            $preserveKeys = VmMath::parseBoolBuiltinArgForFrame(
                $frame,
                1,
                'array_reverse',
                2,
                'preserve_keys'
            );
        }
        $frame->returnVar->array($ht->reverseCopy($preserveKeys));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        // php-src ext/standard/array.c — ArgumentCountError (#23165).
        if (!$this->requireArgCountRangeJit($context, $args, 'array_reverse', 1, 2)) {
            return HashTableHelper::emptyVariable($context)->value;
        }
        $argc = \count($args);

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_reverse() argument #'.((int) $i + 1));
            }
        }
        TypeErrorRaise::ensureLinked($context);
        JitArrayElem::requireArrayParam($context, $args[0], 'array_reverse', 1, 'array');
        // Z_PARAM_BOOL: strict TypeError on null; else null→false + E_DEPRECATED (#31442).
        $preserveKeys = 2 === $argc
            ? JitBoolArg::lowerCoerceZParamBool($context, $args[1], 'array_reverse', 'preserve_keys', 2)
            : null;

        return ArrayReverseRuntime::reverse($context, $args[0], $preserveKeys);
    }
}
