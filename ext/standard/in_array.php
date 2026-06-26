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
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * in_array() for arrays of scalar values (subset of PHP; JIT via ArrayBuiltinHelper).
 */
final class in_array extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs) && 3 !== \count($frame->calledArgs)) {
            throw new \LogicException('in_array() requires two or three arguments');
        }
        $needle = $frame->calledArgs[0]->resolveIndirect();
        $haystack = VmArray::requireArrayParam(
            $frame->calledArgs[1],
            'in_array',
            2,
            'haystack'
        );
        $strict = false;
        if (3 === \count($frame->calledArgs)) {
            $strict = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        $vm = null !== $frame->vmContext ? $frame->vmContext->runtime->vm() : null;
        if (null === $frame->returnVar) {
            return;
        }
        foreach ($haystack->iterate(true) as $value) {
            $stored = $value->resolveIndirect();
            if ($strict ? $needle->identicalTo($stored) : self::looseEquals($needle, $stored, $vm)) {
                $frame->returnVar->bool(true);

                return;
            }
        }
        $frame->returnVar->bool(false);
    }

    public static function looseEquals(Variable $left, Variable $right, ?\PHPCompiler\VM $vm = null): bool
    {
        return $left->resolveIndirect()->equals($right->resolveIndirect(), $vm);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args) && 3 !== \count($args)) {
            throw new \LogicException('in_array() requires two or three arguments');
        }
        $strict = $context->constantFromBool(false);
        if (3 === \count($args)) {
            $strict = JitBoolArg::lower($context, $args[2], 'in_array() strict');
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'in_array() needle');
        }
        JitArrayElem::requireArrayParam($context, $args[1], 'in_array', 2, 'haystack');

        return ArrayBuiltinHelper::inArray($context, $args[0], $args[1], $strict);
    }
}
