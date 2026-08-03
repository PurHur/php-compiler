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
use PHPCompiler\JIT\Builtin\ArrayKeyExistsRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_key_exists() / key_exists() for arrays with int, float, or string keys (php-src subset).
 */
final class array_key_exists extends Internal
{
    public function __construct(string $name = 'array_key_exists')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        $this->requireExactArgCount($frame, $fn, 2);
        $key = $frame->calledArgs[0]->resolveIndirect();
        $array = VmArray::requireArrayParam(
            $frame->calledArgs[1],
            $fn,
            2,
            'array'
        );
        if (Variable::TYPE_NULL === $key->type) {
            HashTable::warnNullArrayKeyExistsIfNeeded($frame);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($array->hasKey($key, false));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        if (!$this->requireExactJitArgCount($context, $args, $fn, 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $key = $args[0];
        $array = $args[1];
        // php-src 8.0+ Z_PARAM_ARRAY — TypeError on null (catchable under AOT; #27447).
        if (JITVariable::TYPE_NULL === $array->type || ($array->isNullConstant ?? false)) {
            JitArrayElem::requireArrayParam($context, $array, $fn, 2, 'array');

            return $context->constantFromInteger(0, 'int1');
        }
        if (JITVariable::TYPE_HASHTABLE !== $array->type
            && !($array->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            JitArrayElem::requireArrayParam($context, $array, $fn, 2, 'array');
            if (JITVariable::TYPE_VALUE === $array->type) {
                return ArrayKeyExistsRuntime::keyExists($context, $key, $array, $fn);
            }

            return $context->constantFromInteger(0, 'int1');
        }

        return ArrayKeyExistsRuntime::keyExists($context, $key, $array, $fn);
    }
}
