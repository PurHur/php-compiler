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

use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;


class strlen extends Internal {

    private const NULL_DEPRECATION =
        'strlen(): Passing null to parameter #1 ($string) of type string is deprecated';

    public function execute(Frame $frame): void {
        if (count($frame->calledArgs) !== 1) {
            throw new \LogicException("Expecting exactly a single argument to strlen()");
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (VmVariable::TYPE_NULL === $var->type) {
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    self::NULL_DEPRECATION,
                    ErrorReporter::E_DEPRECATED,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            if (!is_null($frame->returnVar)) {
                $frame->returnVar->int(0);
            }

            return;
        }
        $string = VmString::coerceStringBuiltinArgNoObject($var, 'strlen', 0, 'string');
        if (!is_null($frame->returnVar)) {
            $frame->returnVar->int(VmString::byteLength($string));
        }
    }

    public function call(Context $context, Variable ... $args): Value {
        if (count($args) !== 1) {
            throw new \LogicException('Too few args passed to strlen()');
        }

        return JitStrlen::lowerLength($context, $args[0]);
    }

}
