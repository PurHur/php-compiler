<?php

# This file is generated, changes you make will be lost.
# Make your changes in /compiler/ext/types/strlen.pre instead.

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\types;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Func\Internal;
use PHPCompiler\Frame;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;

use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;


class strlen extends Internal {

    public function execute(Frame $frame): void {
        $this->requireExactArgCount($frame, 'strlen', 1);
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (null !== $frame->parent && InternalStrictArg::isCallerStrict($frame)) {
            $string = VmString::requireStringBuiltinArg($var, 'strlen', 0, 'string');
        } else {
            $string = VmString::coerceStringBuiltinArgNoObject($var, 'strlen', 0, 'string');
        }
        if (!is_null($frame->returnVar)) {
            $frame->returnVar->int(VmString::byteLength($string));
        }
    }

    public function call(Context $context, Variable ... $args): Value {
        if (!$this->requireExactJitArgCount($context, $args, 'strlen', 1)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        return JitStrlen::lowerLength($context, $args[0]);
    }

}
