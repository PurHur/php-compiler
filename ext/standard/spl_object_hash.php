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
 * spl_object_hash() — 32-char hex identity string (ext/spl/php_spl.c parity, #3172).
 *
 * @see https://github.com/php/php-src/blob/master/ext/spl/php_spl.c php_spl_object_hash()
 */
final class spl_object_hash extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'spl_object_hash', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $id = ObjectHandleSupport::requireObjectId($frame->calledArgs[0], 'spl_object_hash', $frame->vmContext);
        $frame->returnVar->string(ObjectHandleSupport::hashForObjectId($id));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('spl_object_hash() requires exactly one argument');
        }

        return JitSplObjectHash::invoke($context, $args[0]);
    }
}
