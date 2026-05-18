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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * is_scalar() for types supported by this compiler (subset of PHP).
 */
final class is_scalar extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('is_scalar() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(self::isScalar($v));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('is_scalar() requires exactly one argument');
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
            case JITVariable::TYPE_NATIVE_DOUBLE:
            case JITVariable::TYPE_NATIVE_BOOL:
            case JITVariable::TYPE_STRING:
                return $context->constantFromBool(true);
            case JITVariable::TYPE_NULL:
                return $context->constantFromBool(false);
            default:
                throw new \LogicException('is_scalar() does not support this value type in this compiler build');
        }
    }

    public static function isScalar(Variable $v): bool
    {
        switch ($v->type) {
            case Variable::TYPE_INTEGER:
            case Variable::TYPE_FLOAT:
            case Variable::TYPE_BOOLEAN:
            case Variable::TYPE_STRING:
                return true;
            case Variable::TYPE_NULL:
                return false;
            default:
                throw new \LogicException('is_scalar() does not support this value type in this compiler build');
        }
    }
}
