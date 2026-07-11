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
use PHPCompiler\JIT\Builtin\ArrayReplaceKeyRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_replace_key() — replace values for existing keys only (PHP 8.4; ext/standard/array.c, issue #5650).
 *
 * JIT/AOT via {@see ArrayReplaceKeyRuntime}; enum key guards VM-only (#5650).
 */
final class array_replace_key extends Internal
{
    private const ILLEGAL_OFFSET_TYPE = 'Illegal offset type';

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'array_replace_key() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $array = VmArray::requireArrayParam($frame->calledArgs[0], 'array_replace_key', 1, 'array');
        $replacements = VmArray::requireArrayParam($frame->calledArgs[1], 'array_replace_key', 2, 'replacements');
        self::rejectIllegalOffsetKeys($array, $replacements);
        $frame->returnVar->array($array->replaceKeyCopy($replacements));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                'array_replace_key() expects exactly 2 arguments, '.\count($args).' given'
            );
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_replace_key() argument #'.((int) $i + 1));
            }
        }

        return ArrayReplaceKeyRuntime::replaceKey($context, $args[0], $args[1]);
    }

    /**
     * @throws \TypeError when a key is not int/string (enum cases, objects, …)
     */
    private static function rejectIllegalOffsetKeys(HashTable ...$tables): void
    {
        foreach ($tables as $table) {
            foreach ($table->iterateKeyed(true) as [$key]) {
                $keyVar = $key->resolveIndirect();
                if (EnumCaseSupport::isEnumCaseVariable($keyVar)) {
                    throw new \TypeError(self::ILLEGAL_OFFSET_TYPE);
                }
                if (Variable::TYPE_INTEGER !== $keyVar->type && Variable::TYPE_STRING !== $keyVar->type) {
                    throw new \TypeError(self::ILLEGAL_OFFSET_TYPE);
                }
            }
        }
    }
}
