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
use PHPCompiler\JIT\Builtin\ArrayUniqueRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_unique() for arrays of scalar values (ext/standard/array.c php_array_unique subset).
 *
 * VM/JIT SSOT: {@see ArrayUniqueJitHelper}
 */
final class array_unique extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_unique() requires one or two arguments');
        }
        $ht = VmArray::requireArrayParam($frame->calledArgs[0], 'array_unique', 1, 'array');
        $flags = self::resolveVmFlags($frame, $argc);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(ArrayUniqueJitHelper::unique($ht, $flags, $frame));
    }

    private static function resolveVmFlags(Frame $frame, int $argc): int
    {
        if (1 === $argc) {
            return StdlibConstants::SORT_STRING;
        }
        $flagsArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $flagsArg->type) {
            throw new \LogicException('array_unique() flags must be an integer in this compiler build');
        }

        return ArrayUniqueJitHelper::normalizeFlagsForCall($flagsArg->toInt());
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_unique() requires one or two arguments');
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_unique() argument #'.((int) $i + 1));
            }
        }
        TypeErrorRaise::ensureLinked($context);
        JitArrayElem::requireArrayParam($context, $args[0], 'array_unique', 1, 'array');
        $flags = StdlibConstants::SORT_STRING;
        if (2 === $argc) {
            $flags = self::resolveJitFlags($context, $args[1]);
        }

        return ArrayUniqueRuntime::unique($context, $args[0], $flags);
    }

    private static function resolveJitFlags(Context $context, JITVariable $flagsArg): int
    {
        return ArrayUniqueJitHelper::normalizeFlagsForCall(
            VmInternalCompare::resolveJitSortFlags($context, $flagsArg, 'array_unique')
        );
    }
}
