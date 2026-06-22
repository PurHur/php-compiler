<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ini_parse_quantity() — parse ini byte shorthand (Zend/zend_ini.c; issue #6049).
 *
 * VM: {@see VmIniQuantity::parseQuantity()}; JIT/AOT: {@see IniParseQuantityJitHelper} via {@see JitIniParseQuantity}.
 */
final class ini_parse_quantity extends Internal
{
    public function __construct()
    {
        parent::__construct('ini_parse_quantity');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'ini_parse_quantity() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $shorthand = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'ini_parse_quantity',
            0,
            'shorthand'
        );
        $frame->returnVar->int(
            VmIniQuantity::parseQuantity($shorthand, $frame->vmContext)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('ini_parse_quantity() requires exactly one argument');
        }
        if (null !== $args[0]->compileTimeString) {
            $i64 = $context->getTypeFromString('int64');

            return $i64->constInt(
                VmIniQuantity::parseQuantity($args[0]->compileTimeString),
                false
            );
        }

        return JitIniParseQuantity::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'ini_parse_quantity', 0, 'shorthand')
        );
    }
}
