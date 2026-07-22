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
 * settype() — cast variable in place by type name (ext/standard/type.c).
 *
 * VM + JIT in-place casts (ext/standard/type.c; JIT #3151).
 */
final class settype extends Internal
{
    public function __construct()
    {
        parent::__construct('settype');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/type.c — ArgumentCountError (#21964).
        $this->requireExactArgCount($frame, 'settype', 2);
        $slot = $frame->calledArgs[0];
        $typeVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $typeVar->type) {
            throw new \TypeError('settype(): Argument #2 ($type) must be of type string');
        }
        VmSettype::apply($slot, $typeVar->toString(), $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'settype', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        return JitSettype::invoke($context, $args[0], $args[1]);
    }
}
