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
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_combine() for two list arrays of equal length (subset of PHP; JIT via ArrayBuiltinHelper).
 */
final class array_combine extends Internal
{
    public const LENGTH_MISMATCH_ERROR =
        'array_combine(): Argument #1 ($keys) and argument #2 ($values) must have the same number of elements';

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_combine() requires exactly two arguments');
        }
        $keysArg = $frame->calledArgs[0];
        $valuesArg = $frame->calledArgs[1];
        VmArray::requireArrayParam($keysArg, 'array_combine', 1, 'keys');
        VmArray::requireArrayParam($valuesArg, 'array_combine', 2, 'values');
        $keysArg = $keysArg->resolveIndirect();
        $valuesArg = $valuesArg->resolveIndirect();
        $keys = [];
        foreach ($keysArg->toArray()->iterateKeyed(true) as [, $key]) {
            $keys[] = $key;
        }
        $values = [];
        foreach ($valuesArg->toArray()->iterateKeyed(true) as [, $value]) {
            $values[] = $value;
        }
        if (0 === \count($keys) && 0 === \count($values)) {
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->array(new HashTable());

            return;
        }
        if (\count($keys) !== \count($values)) {
            throw new \ValueError(self::LENGTH_MISMATCH_ERROR);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ht = new HashTable();
        $n = \count($keys);
        for ($i = 0; $i < $n; ++$i) {
            $stored = new Variable();
            $stored->copyFrom($values[$i]);
            // Zend array_combine: duplicate keys keep the last value (ext/standard/array.c).
            VmArray::storeCombineKey($ht, $keys[$i], $stored, $frame);
        }
        $frame->returnVar->array($ht);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('array_combine() requires exactly two arguments');
        }
        TypeErrorRaise::ensureLinked($context);

        foreach ([0 => 'keys', 1 => 'values'] as $i => $paramName) {
            $arg = $args[$i];
            if (JITVariable::TYPE_HASHTABLE === $arg->type
                || ($arg->type & JITVariable::IS_NATIVE_ARRAY)
            ) {
                continue;
            }
            if (JITVariable::TYPE_VALUE === $arg->type) {
                JitArrayElem::requireArrayParam($context, $arg, 'array_combine', $i + 1, $paramName);
                continue;
            }
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::emitRaise(
                $context,
                \sprintf(
                    'array_combine(): Argument #%d ($%s) must be of type array, %s given',
                    $i + 1,
                    $paramName,
                    self::jitArgTypeLabel($arg)
                )
            );
            $context->builder->call($context->lookupFunction('abort'));

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return ArrayBuiltinHelper::combine($context, $args[0], $args[1]);
    }

    private static function jitArgTypeLabel(JITVariable $arg): string
    {
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return 'int';
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return 'float';
            case JITVariable::TYPE_NATIVE_BOOL:
                return 'bool';
            case JITVariable::TYPE_STRING:
                return 'string';
            case JITVariable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
