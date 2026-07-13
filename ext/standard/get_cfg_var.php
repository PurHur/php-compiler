<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** get_cfg_var() — read PHP ini cfg values (ext/standard/ini.c, #6119). */
final class get_cfg_var extends Internal
{
    public function __construct()
    {
        parent::__construct('get_cfg_var');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('get_cfg_var() expects exactly 1 argument, '.\count($frame->calledArgs).' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $option = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'get_cfg_var', 0, 'option');
        $result = VmIni::getCfgVar($option);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('get_cfg_var() requires exactly one argument');
        }
        $optionStr = JitStringBuiltinArg::lowerCoercible($context, $args[0], 'get_cfg_var', 0, 'option');

        return JitIni::getCfgVar($context, $optionStr);
    }
}
