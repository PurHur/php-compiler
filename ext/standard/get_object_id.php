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
 * get_object_id() — stable per-instance object handle (ext/standard/basic_functions.c parity, #3537).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.c PHP_FUNCTION(get_object_id)
 * @see https://github.com/php/php-src/blob/master/Zend/zend_objects_API.c zend_object_get_id()
 */
final class get_object_id extends Internal
{
    private const TYPE_ERROR = 'get_object_id(): Argument #1 ($object) must be of type object, %s given';

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('get_object_id() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_OBJECT !== $v->type) {
            throw new \TypeError(\sprintf(self::TYPE_ERROR, self::vmTypeName($v->type)));
        }
        $frame->returnVar->int($v->toObject()->id);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('get_object_id() requires exactly one argument');
        }

        return JitGetObjectId::invoke($context, $args[0]);
    }

    private static function vmTypeName(int $type): string
    {
        switch ($type) {
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
