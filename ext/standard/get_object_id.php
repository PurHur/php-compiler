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
use PHPCompiler\VM\ObjectHandleSupport;
use PHPLLVM\Value;

/**
 * get_object_id() — stable per-instance object handle (ext/standard/basic_functions.c parity, #3537).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.c PHP_FUNCTION(get_object_id)
 * @see https://github.com/php/php-src/blob/master/Zend/zend_objects_API.c zend_object_get_id()
 */
final class get_object_id extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'get_object_id', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(ObjectHandleSupport::requireObjectId($frame->calledArgs[0], 'get_object_id', $frame->vmContext));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('get_object_id() requires exactly one argument');
        }

        return JitGetObjectId::invoke($context, $args[0]);
    }
}
