<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** ini_restore() — reset local INI to php.ini default (ext/standard/ini.c, #3205). */
final class ini_restore extends Internal
{
    public function __construct()
    {
        parent::__construct('ini_restore');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('ini_restore() expects exactly 1 argument, '.\count($frame->calledArgs).' given');
        }
        if (null === $frame->vmContext) {
            return;
        }
        $option = IniOptionArg::vmOption($frame, 'ini_restore');
        VmIni::restore($frame->vmContext, $option);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('ini_restore() requires exactly one argument');
        }
        if (IniOptionArg::jitOptionRejectsWithoutIniCall($context, $args[0])) {
            IniOptionArg::jitOption($context, $args[0], 'ini_restore');

            return $context->getTypeFromString('int32')->constInt(0, false);
        }
        $optionStr = IniOptionArg::jitOption($context, $args[0], 'ini_restore');
        JitIni::restore($context, $optionStr);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
